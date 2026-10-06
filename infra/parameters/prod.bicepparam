using '../main.bicep'

param env = 'prod'
param image = 'tedcacrprod.azurecr.io/tedc:latest'
param alertEmails = [ 'ops-prod@tedc.example.qa' ]
param pgAdminPassword = readEnvironmentVariable('PG_ADMIN_PASSWORD', '')
param tlsSecretId = 'https://tedc-prod-kv.vault.azure.net/secrets/tls-cert'
