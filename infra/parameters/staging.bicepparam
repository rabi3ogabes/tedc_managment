using '../main.bicep'

param env = 'staging'
param image = 'tedcacrstaging.azurecr.io/tedc:latest'
param alertEmails = [ 'ops-staging@tedc.example.qa' ]
param pgAdminPassword = readEnvironmentVariable('PG_ADMIN_PASSWORD', '')
param tlsSecretId = 'https://tedc-staging-kv.vault.azure.net/secrets/tls-cert'
