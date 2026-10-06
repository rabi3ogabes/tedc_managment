// Key Vault (RBAC, purge protection, private endpoint only) and the managed identity every workload uses. No secrets live in environment files.
param name string
param location string
param tags object
param dataSubnetId string
param vaultZoneId string
param tenantId string = subscription().tenantId

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: '${name}-id'
  location: location
  tags: tags
}

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' = {
  name: take('${replace(name, '-', '')}kv', 24)
  location: location
  tags: tags
  properties: {
    tenantId: tenantId
    sku: { family: 'A', name: 'premium' }
    enableRbacAuthorization: true
    enableSoftDelete: true
    softDeleteRetentionInDays: 90
    enablePurgeProtection: true
    publicNetworkAccess: 'Disabled'
    networkAcls: { defaultAction: 'Deny', bypass: 'AzureServices' }
  }
}

resource pe 'Microsoft.Network/privateEndpoints@2023-11-01' = {
  name: '${name}-kv-pe'
  location: location
  tags: tags
  properties: {
    subnet: { id: dataSubnetId }
    privateLinkServiceConnections: [ { name: 'kv', properties: { privateLinkServiceId: vault.id, groupIds: [ 'vault' ] } } ]
  }
}

resource peDns 'Microsoft.Network/privateEndpoints/privateDnsZoneGroups@2023-11-01' = {
  parent: pe
  name: 'default'
  properties: { privateDnsZoneConfigs: [ { name: 'vault', properties: { privateDnsZoneId: vaultZoneId } } ] }
}

// Key Vault Secrets User for the workload identity.
resource secretsUser 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(vault.id, identity.id, 'secrets-user')
  scope: vault
  properties: {
    principalId: identity.properties.principalId
    principalType: 'ServicePrincipal'
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', '4633458b-17de-408a-b874-0445c86b69e6')
  }
}

output identityId string = identity.id
output identityPrincipalId string = identity.properties.principalId
output identityClientId string = identity.properties.clientId
output vaultName string = vault.name
output vaultUri string = vault.properties.vaultUri
output vaultId string = vault.id
