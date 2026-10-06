// Network: spoke VNet with edge / app / data / management subnets, NSGs per subnet, NAT gateway for allow-listed egress, private DNS zones.
param name string
param location string
param tags object
param addressSpace string = '10.40.0.0/16'
@description('Hub VNet resource id to peer with (empty = standalone spoke).')
param hubVnetId string = ''

var privateZones = [
  'privatelink.postgres.database.azure.com'
  'privatelink.blob.core.windows.net'
  'privatelink.vaultcore.azure.net'
  'privatelink.redis.cache.windows.net'
  'privatelink.servicebus.windows.net'
  'privatelink.azurecr.io'
]

resource nsgEdge 'Microsoft.Network/networkSecurityGroups@2023-11-01' = {
  name: '${name}-nsg-edge'
  location: location
  tags: tags
  properties: {
    securityRules: [
      { name: 'allow-https-inbound', properties: { priority: 100, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: 'Internet', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '443' } }
      { name: 'allow-gateway-manager', properties: { priority: 110, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: 'GatewayManager', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '65200-65535' } }
      { name: 'allow-lb-probe', properties: { priority: 120, direction: 'Inbound', access: 'Allow', protocol: '*', sourceAddressPrefix: 'AzureLoadBalancer', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '*' } }
    ]
  }
}

resource nsgApp 'Microsoft.Network/networkSecurityGroups@2023-11-01' = {
  name: '${name}-nsg-app'
  location: location
  tags: tags
  properties: {
    securityRules: [
      { name: 'allow-edge-to-app', properties: { priority: 100, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: '10.40.0.0/24', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRanges: [ '80', '443' ] } }
      { name: 'deny-internet-inbound', properties: { priority: 4000, direction: 'Inbound', access: 'Deny', protocol: '*', sourceAddressPrefix: 'Internet', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '*' } }
    ]
  }
}

resource nsgData 'Microsoft.Network/networkSecurityGroups@2023-11-01' = {
  name: '${name}-nsg-data'
  location: location
  tags: tags
  properties: {
    securityRules: [
      { name: 'allow-app-to-data', properties: { priority: 100, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: '10.40.2.0/23', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRanges: [ '443', '5432', '6380', '5671' ] } }
      { name: 'deny-all-other-inbound', properties: { priority: 4000, direction: 'Inbound', access: 'Deny', protocol: '*', sourceAddressPrefix: '*', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '*' } }
    ]
  }
}

resource nsgMgmt 'Microsoft.Network/networkSecurityGroups@2023-11-01' = {
  name: '${name}-nsg-mgmt'
  location: location
  tags: tags
  properties: { securityRules: [ { name: 'deny-internet-inbound', properties: { priority: 4000, direction: 'Inbound', access: 'Deny', protocol: '*', sourceAddressPrefix: 'Internet', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '*' } } ] }
}

resource natIp 'Microsoft.Network/publicIPAddresses@2023-11-01' = {
  name: '${name}-nat-ip'
  location: location
  tags: tags
  sku: { name: 'Standard' }
  zones: [ '1', '2', '3' ]
  properties: { publicIPAllocationMethod: 'Static' }
}

resource nat 'Microsoft.Network/natGateways@2023-11-01' = {
  name: '${name}-nat'
  location: location
  tags: tags
  sku: { name: 'Standard' }
  properties: { publicIpAddresses: [ { id: natIp.id } ], idleTimeoutInMinutes: 10 }
}

resource vnet 'Microsoft.Network/virtualNetworks@2023-11-01' = {
  name: '${name}-vnet'
  location: location
  tags: tags
  properties: {
    addressSpace: { addressPrefixes: [ addressSpace ] }
    subnets: [
      { name: 'edge', properties: { addressPrefix: '10.40.0.0/24', networkSecurityGroup: { id: nsgEdge.id } } }
      { name: 'app', properties: { addressPrefix: '10.40.2.0/23', networkSecurityGroup: { id: nsgApp.id }, natGateway: { id: nat.id }, delegations: [ { name: 'aca', properties: { serviceName: 'Microsoft.App/environments' } } ] } }
      { name: 'data', properties: { addressPrefix: '10.40.4.0/24', networkSecurityGroup: { id: nsgData.id }, privateEndpointNetworkPolicies: 'Enabled' } }
      { name: 'postgres', properties: { addressPrefix: '10.40.5.0/24', networkSecurityGroup: { id: nsgData.id }, delegations: [ { name: 'pg', properties: { serviceName: 'Microsoft.DBforPostgreSQL/flexibleServers' } } ] } }
      { name: 'apim', properties: { addressPrefix: '10.40.6.0/27', networkSecurityGroup: { id: nsgMgmt.id } } }
      { name: 'mgmt', properties: { addressPrefix: '10.40.7.0/27', networkSecurityGroup: { id: nsgMgmt.id } } }
    ]
  }
}

resource zones 'Microsoft.Network/privateDnsZones@2020-06-01' = [for z in privateZones: {
  name: z
  location: 'global'
  tags: tags
}]

resource links 'Microsoft.Network/privateDnsZones/virtualNetworkLinks@2020-06-01' = [for (z, i) in privateZones: {
  parent: zones[i]
  name: '${name}-link'
  location: 'global'
  properties: { registrationEnabled: false, virtualNetwork: { id: vnet.id } }
}]

resource peering 'Microsoft.Network/virtualNetworks/virtualNetworkPeerings@2023-11-01' = if (!empty(hubVnetId)) {
  parent: vnet
  name: 'to-hub'
  properties: { remoteVirtualNetwork: { id: hubVnetId }, allowForwardedTraffic: true, allowVirtualNetworkAccess: true, useRemoteGateways: false }
}

output vnetId string = vnet.id
output edgeSubnetId string = '${vnet.id}/subnets/edge'
output appSubnetId string = '${vnet.id}/subnets/app'
output dataSubnetId string = '${vnet.id}/subnets/data'
output postgresSubnetId string = '${vnet.id}/subnets/postgres'
output apimSubnetId string = '${vnet.id}/subnets/apim'
output natPublicIp string = natIp.properties.ipAddress
output zoneIds object = {
  postgres: zones[0].id
  blob: zones[1].id
  vault: zones[2].id
  redis: zones[3].id
  servicebus: zones[4].id
  acr: zones[5].id
}
