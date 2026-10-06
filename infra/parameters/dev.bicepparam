using '../main.bicep'

param env = 'dev'
param image = 'tedcacrdev.azurecr.io/tedc:latest'
param alertEmails = [ 'ops-dev@tedc.example.qa' ]
param pgAdminPassword = readEnvironmentVariable('PG_ADMIN_PASSWORD', '')
param tlsSecretId = 'https://tedc-dev-kv.vault.azure.net/secrets/tls-cert'
