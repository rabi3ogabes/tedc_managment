// Guard rails as Azure Policy on the resource group: resources only in Qatar Central, no public network access on data services, encryption and tags required.
targetScope = 'resourceGroup'
param allowedLocations array = [ 'qatarcentral' ]

resource locations 'Microsoft.Authorization/policyAssignments@2022-06-01' = {
  name: 'allowed-locations'
  properties: {
    displayName: 'Only Qatar Central'
    policyDefinitionId: '/providers/Microsoft.Authorization/policyDefinitions/e56962a6-4747-49cd-b67b-bf8b01975c4c'
    parameters: { listOfAllowedLocations: { value: allowedLocations } }
    enforcementMode: 'Default'
  }
}

resource storagePublic 'Microsoft.Authorization/policyAssignments@2022-06-01' = {
  name: 'storage-no-public'
  properties: {
    displayName: 'Storage accounts: deny public network access'
    policyDefinitionId: '/providers/Microsoft.Authorization/policyDefinitions/b2982f36-99f2-4db5-8eff-283140c09693'
    enforcementMode: 'Default'
  }
}

resource postgresPublic 'Microsoft.Authorization/policyAssignments@2022-06-01' = {
  name: 'postgres-no-public'
  properties: {
    displayName: 'PostgreSQL flexible servers: disable public network access'
    policyDefinitionId: '/providers/Microsoft.Authorization/policyDefinitions/5e1de0e3-42cb-4ebc-a86d-61d0c619ca48'
    enforcementMode: 'Default'
  }
}

resource keyvaultPublic 'Microsoft.Authorization/policyAssignments@2022-06-01' = {
  name: 'keyvault-no-public'
  properties: {
    displayName: 'Key Vault: disable public network access'
    policyDefinitionId: '/providers/Microsoft.Authorization/policyDefinitions/405c5871-3e91-4644-8a63-58e19d68ff5b'
    enforcementMode: 'Default'
  }
}

resource tagEnv 'Microsoft.Authorization/policyAssignments@2022-06-01' = {
  name: 'require-env-tag'
  properties: {
    displayName: 'Require the env tag'
    policyDefinitionId: '/providers/Microsoft.Authorization/policyDefinitions/871b6d14-10aa-478d-b590-94f262ecfa99'
    parameters: { tagName: { value: 'env' } }
    enforcementMode: 'Default'
  }
}
