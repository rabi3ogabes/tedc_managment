// Edge: Application Gateway WAF v2 (OWASP CRS + bot protection, TLS 1.2+) in front of the internal Container Apps environment.
// API Management (internal mode) fronts partner/external APIs only; the SPA's own traffic goes through the gateway directly — one hop fewer, same WAF.
param name string
param location string
param tags object
param edgeSubnetId string
param backendFqdn string
@allowed([ 'dev', 'staging', 'prod' ])
param env string
param enableDdos bool = env == 'prod'
param identityId string
@description('Key Vault secret id of the TLS certificate (versionless).')
param tlsSecretId string

resource waf 'Microsoft.Network/ApplicationGatewayWebApplicationFirewallPolicies@2023-11-01' = {
  name: '${name}-waf'
  location: location
  tags: tags
  properties: {
    policySettings: { state: 'Enabled', mode: 'Prevention', requestBodyCheck: true, maxRequestBodySizeInKb: 128, fileUploadLimitInMb: 100 }
    managedRules: {
      managedRuleSets: [
        { ruleSetType: 'OWASP', ruleSetVersion: '3.2' }
        { ruleSetType: 'Microsoft_BotManagerRuleSet', ruleSetVersion: '1.0' }
      ]
    }
    customRules: [
      { name: 'RateLimitLogin', priority: 10, ruleType: 'RateLimitRule', action: 'Block', rateLimitDuration: 'OneMin', rateLimitThreshold: 60, groupByUserSession: [ { groupByVariables: [ { variableName: 'ClientAddr' } ] } ]
        matchConditions: [ { matchVariables: [ { variableName: 'RequestUri' } ], operator: 'Contains', matchValues: [ '/api/v1/auth/login' ], transforms: [ 'Lowercase' ] } ] }
    ]
  }
}

resource pip 'Microsoft.Network/publicIPAddresses@2023-11-01' = {
  name: '${name}-agw-ip'
  location: location
  tags: tags
  sku: { name: 'Standard' }
  zones: [ '1', '2', '3' ]
  properties: { publicIPAllocationMethod: 'Static', ddosSettings: enableDdos ? { protectionMode: 'Enabled' } : null }
}

resource agw 'Microsoft.Network/applicationGateways@2023-11-01' = {
  name: '${name}-agw'
  location: location
  tags: tags
  zones: [ '1', '2', '3' ]
  identity: { type: 'UserAssigned', userAssignedIdentities: { '${identityId}': {} } }
  properties: {
    sslCertificates: [ { name: 'tls', properties: { keyVaultSecretId: tlsSecretId } } ]
    sku: { name: 'WAF_v2', tier: 'WAF_v2' }
    autoscaleConfiguration: { minCapacity: env == 'dev' ? 1 : 2, maxCapacity: env == 'prod' ? 20 : 4 }
    firewallPolicy: { id: waf.id }
    sslPolicy: { policyType: 'Predefined', policyName: 'AppGwSslPolicy20220101S' }
    gatewayIPConfigurations: [ { name: 'gw', properties: { subnet: { id: edgeSubnetId } } } ]
    frontendIPConfigurations: [ { name: 'public', properties: { publicIPAddress: { id: pip.id } } } ]
    frontendPorts: [ { name: 'https', properties: { port: 443 } } ]
    backendAddressPools: [ { name: 'aca', properties: { backendAddresses: [ { fqdn: backendFqdn } ] } } ]
    probes: [ { name: 'ready', properties: { protocol: 'Https', path: '/api/v1/public/health/ready', interval: 15, timeout: 10, unhealthyThreshold: 3, pickHostNameFromBackendHttpSettings: true, match: { statusCodes: [ '200-399' ] } } } ]
    backendHttpSettingsCollection: [ { name: 'https', properties: { port: 443, protocol: 'Https', cookieBasedAffinity: 'Disabled', pickHostNameFromBackendAddress: true, requestTimeout: 60, probe: { id: resourceId('Microsoft.Network/applicationGateways/probes', '${name}-agw', 'ready') } } } ]
    httpListeners: [ { name: 'listener', properties: { frontendIPConfiguration: { id: resourceId('Microsoft.Network/applicationGateways/frontendIPConfigurations', '${name}-agw', 'public') }, frontendPort: { id: resourceId('Microsoft.Network/applicationGateways/frontendPorts', '${name}-agw', 'https') }, protocol: 'Https', sslCertificate: { id: resourceId('Microsoft.Network/applicationGateways/sslCertificates', '${name}-agw', 'tls') } } } ]
    requestRoutingRules: [ { name: 'route', properties: { ruleType: 'Basic', priority: 100, httpListener: { id: resourceId('Microsoft.Network/applicationGateways/httpListeners', '${name}-agw', 'listener') }, backendAddressPool: { id: resourceId('Microsoft.Network/applicationGateways/backendAddressPools', '${name}-agw', 'aca') }, backendHttpSettings: { id: resourceId('Microsoft.Network/applicationGateways/backendHttpSettingsCollection', '${name}-agw', 'https') } } } ]
  }
}

output publicIp string = pip.properties.ipAddress
output gatewayId string = agw.id
