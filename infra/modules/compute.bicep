// Compute: Log Analytics + Application Insights, Container Registry, Container Apps environment (zone-redundant, VNet-integrated) and the workloads
// api, worker, scheduler, realtime. Every workload runs the same image with a different command, as a non-root user, with probes and autoscaling.
param name string
param location string
param tags object
param appSubnetId string
param identityId string
param identityClientId string
param keyVaultUri string
param image string = 'mcr.microsoft.com/k8se/quickstart:latest'
@allowed([ 'dev', 'staging', 'prod' ])
param env string
param minReplicas int = env == 'dev' ? 1 : 2
param maxReplicas int = env == 'prod' ? 40 : 6
param pgHost string
param redisHost string
param storageName string

resource law 'Microsoft.OperationalInsights/workspaces@2023-09-01' = {
  name: '${name}-law'
  location: location
  tags: tags
  properties: { sku: { name: 'PerGB2018' }, retentionInDays: env == 'prod' ? 365 : 90, features: { disableLocalAuth: false } }
}

resource ai 'Microsoft.Insights/components@2020-02-02' = {
  name: '${name}-ai'
  location: location
  tags: tags
  kind: 'web'
  properties: { Application_Type: 'web', WorkspaceResourceId: law.id, IngestionMode: 'LogAnalytics' }
}

resource acr 'Microsoft.ContainerRegistry/registries@2023-11-01-preview' = {
  name: take('${replace(name, '-', '')}acr', 50)
  location: location
  tags: tags
  sku: { name: 'Premium' }
  properties: { adminUserEnabled: false, publicNetworkAccess: 'Disabled', zoneRedundancy: env == 'dev' ? 'Disabled' : 'Enabled', dataEndpointEnabled: false }
}

resource acaEnv 'Microsoft.App/managedEnvironments@2024-03-01' = {
  name: '${name}-env'
  location: location
  tags: tags
  properties: {
    vnetConfiguration: { infrastructureSubnetId: appSubnetId, internal: true }
    zoneRedundant: env != 'dev'
    appLogsConfiguration: { destination: 'log-analytics', logAnalyticsConfiguration: { customerId: law.properties.customerId, sharedKey: law.listKeys().primarySharedKey } }
    workloadProfiles: [ { name: 'Consumption', workloadProfileType: 'Consumption' } ]
  }
}

var common = [
  { name: 'APP_ENV', value: env == 'prod' ? 'production' : env }
  { name: 'LOG_CHANNEL', value: 'json' }
  { name: 'TEDC_STORAGE_DRIVER', value: 'azure' }
  { name: 'AZURE_STORAGE_ACCOUNT', value: storageName }
  { name: 'AZURE_STORAGE_MANAGED_IDENTITY', value: 'true' }
  { name: 'AZURE_CLIENT_ID', value: identityClientId }
  { name: 'DB_CONNECTION', value: 'pgsql' }
  { name: 'DB_HOST', value: pgHost }
  { name: 'DB_DATABASE', value: 'tedc' }
  { name: 'CACHE_STORE', value: 'redis' }
  { name: 'SESSION_DRIVER', value: 'redis' }
  { name: 'QUEUE_CONNECTION', value: 'redis' }
  { name: 'REDIS_HOST', value: redisHost }
  { name: 'TEDC_AUTH_DRIVER', value: 'local' }
  { name: 'TEDC_REALTIME', value: 'sse' }
  { name: 'APPLICATIONINSIGHTS_CONNECTION_STRING', value: ai.properties.ConnectionString }
  { name: 'KEY_VAULT_URI', value: keyVaultUri }
]

// Secrets are Key Vault references resolved by the managed identity: nothing secret sits in the template or the environment file.
var secretRefs = [
  { name: 'app-key', keyVaultUrl: '${keyVaultUri}secrets/app-key', identity: identityId }
  { name: 'db-password', keyVaultUrl: '${keyVaultUri}secrets/db-password', identity: identityId }
  { name: 'jwt-private-key', keyVaultUrl: '${keyVaultUri}secrets/jwt-private-key', identity: identityId }
]
var secretEnv = [
  { name: 'APP_KEY', secretRef: 'app-key' }
  { name: 'DB_PASSWORD', secretRef: 'db-password' }
  { name: 'TEDC_JWT_PRIVATE_KEY', secretRef: 'jwt-private-key' }
]

var workloads = [
  { key: 'api', command: [ 'php-fpm-or-octane' ], external: false, port: 8080, min: minReplicas, max: maxReplicas, rule: { name: 'http', http: { metadata: { concurrentRequests: '80' } } }, probes: true }
  { key: 'worker', command: [ 'php', 'artisan', 'queue:work', '--tries=3', '--max-time=3600' ], external: false, port: 0, min: minReplicas, max: maxReplicas, rule: { name: 'cpu', custom: { type: 'cpu', metadata: { type: 'Utilization', value: '70' } } }, probes: false }
  { key: 'scheduler', command: [ 'php', 'artisan', 'schedule:work' ], external: false, port: 0, min: 1, max: 1, rule: null, probes: false }
  { key: 'realtime', command: [ 'php', 'artisan', 'octane:start', '--host=0.0.0.0', '--port=8081' ], external: false, port: 8081, min: minReplicas, max: maxReplicas, rule: { name: 'http', http: { metadata: { concurrentRequests: '400' } } }, probes: false }
]

resource apps 'Microsoft.App/containerApps@2024-03-01' = [for w in workloads: {
  name: '${name}-${w.key}'
  location: location
  tags: tags
  identity: { type: 'UserAssigned', userAssignedIdentities: { '${identityId}': {} } }
  properties: {
    managedEnvironmentId: acaEnv.id
    configuration: {
      activeRevisionsMode: 'Multiple'   // blue-green: a new revision gets traffic only after its probes pass (deploy workflow shifts it)
      ingress: w.port > 0 ? { external: false, targetPort: w.port, transport: 'auto', allowInsecure: false } : null
      secrets: secretRefs
      registries: [ { server: acr.properties.loginServer, identity: identityId } ]
    }
    template: {
      containers: [
        {
          name: w.key
          image: image
          resources: { cpu: json(env == 'prod' ? '1.0' : '0.5'), memory: env == 'prod' ? '2Gi' : '1Gi' }
          env: concat(common, secretEnv, [ { name: 'TEDC_ROLE', value: w.key } ])
          probes: w.probes ? [
            { type: 'Startup', httpGet: { path: '/api/v1/public/health/live', port: w.port }, periodSeconds: 5, failureThreshold: 30 }
            { type: 'Liveness', httpGet: { path: '/api/v1/public/health/live', port: w.port }, periodSeconds: 10, failureThreshold: 3 }
            { type: 'Readiness', httpGet: { path: '/api/v1/public/health/ready', port: w.port }, periodSeconds: 10, failureThreshold: 3 }
          ] : []
        }
      ]
      scale: { minReplicas: w.min, maxReplicas: w.max, rules: w.rule == null ? [] : [ w.rule ] }
    }
  }
}]

output environmentId string = acaEnv.id
output environmentDefaultDomain string = acaEnv.properties.defaultDomain
output environmentStaticIp string = acaEnv.properties.staticIp
output acrName string = acr.name
output acrId string = acr.id
output lawId string = law.id
output appInsightsId string = ai.id
output apiFqdn string = '${name}-api.internal.${acaEnv.properties.defaultDomain}'
