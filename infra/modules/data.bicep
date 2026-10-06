// Data tier: PostgreSQL Flexible Server (zone-redundant HA, PITR 35 days, no geo-backup so data stays in Qatar), Redis, Storage (private, soft delete, versioning), Service Bus.
param name string
param location string
param tags object
param postgresSubnetId string
param dataSubnetId string
param postgresZoneId string
param blobZoneId string
param redisZoneId string
param serviceBusZoneId string
param workloadPrincipalId string
@allowed([ 'dev', 'staging', 'prod' ])
param env string
@secure()
param pgAdminPassword string
param pgSku string = env == 'prod' ? 'Standard_D4ds_v5' : 'Standard_B2s'
param pgTier string = env == 'prod' ? 'GeneralPurpose' : 'Burstable'
param pgStorageGb int = env == 'prod' ? 512 : 64
param haMode string = env == 'dev' ? 'Disabled' : 'ZoneRedundant'
param containers array = [ 'documents', 'materials', 'submissions', 'certificates', 'public-assets' ]

resource pg 'Microsoft.DBforPostgreSQL/flexibleServers@2023-12-01-preview' = {
  name: '${name}-pg'
  location: location
  tags: tags
  sku: { name: pgSku, tier: pgTier }
  properties: {
    version: '16'
    administratorLogin: 'tedcadmin'
    administratorLoginPassword: pgAdminPassword
    storage: { storageSizeGB: pgStorageGb, autoGrow: 'Enabled' }
    backup: { backupRetentionDays: 35, geoRedundantBackup: 'Disabled' }
    highAvailability: { mode: haMode }
    network: { delegatedSubnetResourceId: postgresSubnetId, privateDnsZoneArmResourceId: postgresZoneId, publicNetworkAccess: 'Disabled' }
    authConfig: { activeDirectoryAuth: 'Enabled', passwordAuth: 'Enabled' }
  }
}

resource pgDb 'Microsoft.DBforPostgreSQL/flexibleServers/databases@2023-12-01-preview' = {
  parent: pg
  name: 'tedc'
  properties: { charset: 'UTF8', collation: 'en_US.utf8' }
}

resource pgExt 'Microsoft.DBforPostgreSQL/flexibleServers/configurations@2023-12-01-preview' = {
  parent: pg
  name: 'azure.extensions'
  properties: { value: 'VECTOR,PG_TRGM,PGCRYPTO,UUID-OSSP', source: 'user-override' }
}

resource redis 'Microsoft.Cache/redis@2023-08-01' = {
  name: '${name}-redis'
  location: location
  tags: tags
  zones: env == 'dev' ? null : [ '1', '2' ]
  properties: {
    sku: { name: env == 'prod' ? 'Premium' : 'Standard', family: env == 'prod' ? 'P' : 'C', capacity: env == 'prod' ? 1 : 1 }
    enableNonSslPort: false
    minimumTlsVersion: '1.2'
    publicNetworkAccess: 'Disabled'
  }
}

resource storage 'Microsoft.Storage/storageAccounts@2023-05-01' = {
  name: take('${replace(name, '-', '')}st', 24)
  location: location
  tags: tags
  sku: { name: env == 'dev' ? 'Standard_LRS' : 'Standard_ZRS' }   // ZRS: zone-redundant inside Qatar; never GRS (residency)
  kind: 'StorageV2'
  properties: {
    minimumTlsVersion: 'TLS1_2'
    allowBlobPublicAccess: false
    allowSharedKeyAccess: false
    supportsHttpsTrafficOnly: true
    publicNetworkAccess: 'Disabled'
    networkAcls: { defaultAction: 'Deny', bypass: 'AzureServices' }
    encryption: { services: { blob: { enabled: true, keyType: 'Account' } }, keySource: 'Microsoft.Storage' }
  }
}

resource blobSvc 'Microsoft.Storage/storageAccounts/blobServices@2023-05-01' = {
  parent: storage
  name: 'default'
  properties: {
    deleteRetentionPolicy: { enabled: true, days: 30 }
    containerDeleteRetentionPolicy: { enabled: true, days: 30 }
    isVersioningEnabled: true
    changeFeed: { enabled: true, retentionInDays: 30 }
  }
}

resource cont 'Microsoft.Storage/storageAccounts/blobServices/containers@2023-05-01' = [for c in containers: {
  parent: blobSvc
  name: c
  properties: { publicAccess: 'None' }
}]

resource sb 'Microsoft.ServiceBus/namespaces@2022-10-01-preview' = {
  name: '${name}-sb'
  location: location
  tags: tags
  sku: { name: 'Premium', tier: 'Premium', capacity: 1 }
  properties: { minimumTlsVersion: '1.2', publicNetworkAccess: 'Disabled', zoneRedundant: env != 'dev' }
}

resource sbQueue 'Microsoft.ServiceBus/namespaces/queues@2022-10-01-preview' = [for q in [ 'default', 'outbox', 'notifications' ]: {
  parent: sb
  name: q
  properties: { maxDeliveryCount: 10, deadLetteringOnMessageExpiration: true, lockDuration: 'PT2M' }
}]

// Private endpoints with their DNS registration.
var endpoints = [
  { key: 'redis', id: redis.id, group: 'redisCache', zone: redisZoneId }
  { key: 'blob', id: storage.id, group: 'blob', zone: blobZoneId }
  { key: 'sb', id: sb.id, group: 'namespace', zone: serviceBusZoneId }
]

resource pes 'Microsoft.Network/privateEndpoints@2023-11-01' = [for e in endpoints: {
  name: '${name}-${e.key}-pe'
  location: location
  tags: tags
  properties: {
    subnet: { id: dataSubnetId }
    privateLinkServiceConnections: [ { name: e.key, properties: { privateLinkServiceId: e.id, groupIds: [ e.group ] } } ]
  }
}]

resource peDns 'Microsoft.Network/privateEndpoints/privateDnsZoneGroups@2023-11-01' = [for (e, i) in endpoints: {
  parent: pes[i]
  name: 'default'
  properties: { privateDnsZoneConfigs: [ { name: e.key, properties: { privateDnsZoneId: e.zone } } ] }
}]

// The workload identity reads and writes blobs and uses Service Bus without any key.
resource blobRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(storage.id, workloadPrincipalId, 'blob-contributor')
  scope: storage
  properties: { principalId: workloadPrincipalId, principalType: 'ServicePrincipal', roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', 'ba92f5b4-2d11-453d-a403-e96b0029c9fe') }
}

resource delegator 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(storage.id, workloadPrincipalId, 'blob-delegator')
  scope: storage
  properties: { principalId: workloadPrincipalId, principalType: 'ServicePrincipal', roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', 'db58b8e5-c6ad-4a2a-8342-4190687cbf4a') }
}

resource sbRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(sb.id, workloadPrincipalId, 'sb-data-owner')
  scope: sb
  properties: { principalId: workloadPrincipalId, principalType: 'ServicePrincipal', roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', '090c5cfd-751d-490a-894a-3ce6f1109419') }
}

output pgId string = pg.id
output pgHost string = pg.properties.fullyQualifiedDomainName
output storageId string = storage.id
output storageName string = storage.name
output redisHost string = redis.properties.hostName
output serviceBusName string = sb.name
