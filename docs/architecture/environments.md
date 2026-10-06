# Environments

| | dev | staging | prod |
|---|---|---|---|
| Purpose | developers, feature branches | release qualification, user training, VAPT | live service |
| Topology | single zone, small SKUs | **same as prod**, lower capacity, HA on | zone-redundant, HA |
| Subscription / RG | isolated | `rg-tedc-staging` | `rg-tedc-prod` |
| Data | synthetic seed | synthetic seed (never production data) | real |
| Key Vault / DB | own | own | own |
| Access (RBAC) | developers | release engineers, QA | operations only, break-glass audited |
| Deploys | every merge | every release candidate | after manual approval |
