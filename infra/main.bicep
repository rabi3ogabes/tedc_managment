// TEDC on Azure Qatar Central. One template, three environments (dev, staging, prod) with the same topology; size and redundancy follow the parameters.
// Deploy: az deployment group create -g rg-tedc-<env> -f infra/main.bicep -p infra/parameters/<env>.bicepparam
targetScope = 'resourceGroup'

@allowed([ 'dev', 'staging', 'prod' ])
param env string
param location string = 'qatarcentral'
param prefix string = 'tedc'
param alertEmails array
param alertSmsNumbers array = []
param teamsWebhook string = ''
param image string
@secure()
param pgAdminPassword string
@description('Key Vault secret id (versionless) of the TLS certificate for the public host name.')
param tlsSecretId string
param hubVnetId string = ''

var name = '${prefix}-${env}'
var tags = { env: env, system: 'tedc', owner: 'training-development-center', dataResidency: 'qatar' }

module network 'modules/network.bicep' = { name: 'network', params: { name: name, location: location, tags: tags, hubVnetId: hubVnetId } }

module security 'modules/security.bicep' = { name: 'security', params: { name: name, location: location, tags: tags, dataSubnetId: network.outputs.dataSubnetId, vaultZoneId: network.outputs.zoneIds.vault } }

module data 'modules/data.bicep' = {
  name: 'data'
  params: {
    name: name, location: location, tags: tags, env: env, pgAdminPassword: pgAdminPassword
    postgresSubnetId: network.outputs.postgresSubnetId, dataSubnetId: network.outputs.dataSubnetId
    postgresZoneId: network.outputs.zoneIds.postgres, blobZoneId: network.outputs.zoneIds.blob, redisZoneId: network.outputs.zoneIds.redis, serviceBusZoneId: network.outputs.zoneIds.servicebus
    workloadPrincipalId: security.outputs.identityPrincipalId
  }
}

module compute 'modules/compute.bicep' = {
  name: 'compute'
  params: {
    name: name, location: location, tags: tags, env: env, image: image
    appSubnetId: network.outputs.appSubnetId, identityId: security.outputs.identityId, identityClientId: security.outputs.identityClientId, keyVaultUri: security.outputs.vaultUri
    pgHost: data.outputs.pgHost, redisHost: data.outputs.redisHost, storageName: data.outputs.storageName
  }
}

module edge 'modules/edge.bicep' = { name: 'edge', params: { name: name, location: location, tags: tags, env: env, edgeSubnetId: network.outputs.edgeSubnetId, backendFqdn: compute.outputs.apiFqdn, identityId: security.outputs.identityId, tlsSecretId: tlsSecretId } }

module monitoring 'modules/monitoring.bicep' = { name: 'monitoring', params: { name: name, tags: tags, appInsightsId: compute.outputs.appInsightsId, lawId: compute.outputs.lawId, pgId: data.outputs.pgId, alertEmails: alertEmails, alertSmsNumbers: alertSmsNumbers, teamsWebhook: teamsWebhook } }

module backup 'modules/backup.bicep' = if (env != 'dev') { name: 'backup', params: { name: name, location: location, tags: tags, pgId: data.outputs.pgId, storageId: data.outputs.storageId } }

module policy 'modules/policy.bicep' = { name: 'policy' }

output publicIp string = edge.outputs.publicIp
output natEgressIp string = network.outputs.natPublicIp
output keyVault string = security.outputs.vaultName
