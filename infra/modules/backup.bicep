// Backup: an immutable Backup vault in Qatar (locally/zone redundant — never geo-redundant) with a monthly long-term policy for PostgreSQL and Blob operational backup.
param name string
param location string
param tags object
param pgId string
param storageId string

resource vault 'Microsoft.DataProtection/backupVaults@2024-03-01' = {
  name: '${name}-bkp'
  location: location
  tags: tags
  identity: { type: 'SystemAssigned' }
  properties: {
    storageSettings: [ { datastoreType: 'VaultStore', type: 'ZoneRedundant' } ]
    securitySettings: { immutabilitySettings: { state: 'Unlocked' }, softDeleteSettings: { state: 'On', retentionDurationInDays: 14 } }
  }
}

resource pgPolicy 'Microsoft.DataProtection/backupVaults/backupPolicies@2024-03-01' = {
  parent: vault
  name: 'pg-monthly'
  properties: {
    objectType: 'BackupPolicy'
    datasourceTypes: [ 'Microsoft.DBforPostgreSQL/flexibleServers' ]
    policyRules: [
      { objectType: 'AzureBackupRule', name: 'BackupMonthly', backupParameters: { objectType: 'AzureBackupParams', backupType: 'Full' }, dataStore: { dataStoreType: 'VaultStore', objectType: 'DataStoreInfoBase' }, trigger: { objectType: 'ScheduleBasedTriggerContext', schedule: { repeatingTimeIntervals: [ 'R/2026-01-01T02:00:00+03:00/P1M' ] }, taggingCriteria: [ { isDefault: true, taggingPriority: 99, tagInfo: { tagName: 'Default' } } ] } }
      { objectType: 'AzureRetentionRule', name: 'Default', isDefault: true, lifecycles: [ { deleteAfter: { objectType: 'AbsoluteDeleteOption', duration: 'P7Y' }, sourceDataStore: { dataStoreType: 'VaultStore', objectType: 'DataStoreInfoBase' } } ] }
    ]
  }
}

resource blobPolicy 'Microsoft.DataProtection/backupVaults/backupPolicies@2024-03-01' = {
  parent: vault
  name: 'blob-operational'
  properties: {
    objectType: 'BackupPolicy'
    datasourceTypes: [ 'Microsoft.Storage/storageAccounts/blobServices' ]
    policyRules: [
      { objectType: 'AzureRetentionRule', name: 'Default', isDefault: true, lifecycles: [ { deleteAfter: { objectType: 'AbsoluteDeleteOption', duration: 'P30D' }, sourceDataStore: { dataStoreType: 'OperationalStore', objectType: 'DataStoreInfoBase' } } ] }
    ]
  }
}

output vaultId string = vault.id
output pgPolicyId string = pgPolicy.id
output blobPolicyId string = blobPolicy.id
output protectedPg string = pgId
output protectedStorage string = storageId
