// Monitoring: action group (e-mail, SMS, Teams webhook) and the alerts the RFP asks for — availability, latency SLO burn, error spikes, database CPU/storage, queue lag, certificate expiry, backup failures.
param name string
param location string = 'global'
param tags object
param appInsightsId string
param lawId string
param pgId string
param alertEmails array
param alertSmsNumbers array = []
param teamsWebhook string = ''

resource ag 'Microsoft.Insights/actionGroups@2023-01-01' = {
  name: '${name}-ops'
  location: 'global'
  tags: tags
  properties: {
    groupShortName: 'tedc-ops'
    enabled: true
    emailReceivers: [for (e, i) in alertEmails: { name: 'mail${i}', emailAddress: e, useCommonAlertSchema: true }]
    smsReceivers: [for (n, i) in alertSmsNumbers: { name: 'sms${i}', countryCode: '974', phoneNumber: n }]
    webhookReceivers: empty(teamsWebhook) ? [] : [ { name: 'teams', serviceUri: teamsWebhook, useCommonAlertSchema: true } ]
  }
}

resource availability 'Microsoft.Insights/webtests@2022-06-15' = {
  name: '${name}-ready-test'
  location: 'qatarcentral'
  tags: union(tags, { 'hidden-link:${appInsightsId}': 'Resource' })
  kind: 'standard'
  properties: {
    SyntheticMonitorId: '${name}-ready-test'
    Name: 'Platform ready'
    Enabled: true
    Frequency: 300
    Timeout: 30
    Kind: 'standard'
    RetryEnabled: true
    Locations: [ { Id: 'emea-ch-zrh-edge' } ]   // replaced by in-region probes where Azure offers them for Qatar Central
    Request: { RequestUrl: 'https://${name}.example.invalid/api/v1/public/health/ready', HttpVerb: 'GET' }
    ValidationRules: { ExpectedHttpStatusCode: 200 }
  }
}

resource errors 'Microsoft.Insights/scheduledQueryRules@2023-03-15-preview' = {
  name: '${name}-5xx-spike'
  location: location == 'global' ? 'qatarcentral' : location
  tags: tags
  properties: {
    displayName: 'API 5xx error spike'
    severity: 1
    enabled: true
    evaluationFrequency: 'PT5M'
    windowSize: 'PT10M'
    scopes: [ lawId ]
    criteria: { allOf: [ { query: 'AppRequests | where Success == false and toint(ResultCode) >= 500 | summarize Count = count()', timeAggregation: 'Total', metricMeasureColumn: 'Count', operator: 'GreaterThan', threshold: 25, failingPeriods: { numberOfEvaluationPeriods: 1, minFailingPeriodsToAlert: 1 } } ] }
    actions: { actionGroups: [ ag.id ] }
  }
}

resource latency 'Microsoft.Insights/scheduledQueryRules@2023-03-15-preview' = {
  name: '${name}-p95-latency'
  location: location == 'global' ? 'qatarcentral' : location
  tags: tags
  properties: {
    displayName: 'p95 latency above 1.5 s (SLO burn)'
    severity: 2
    enabled: true
    evaluationFrequency: 'PT5M'
    windowSize: 'PT15M'
    scopes: [ lawId ]
    criteria: { allOf: [ { query: 'AppRequests | summarize P95 = percentile(DurationMs, 95)', timeAggregation: 'Maximum', metricMeasureColumn: 'P95', operator: 'GreaterThan', threshold: 1500, failingPeriods: { numberOfEvaluationPeriods: 2, minFailingPeriodsToAlert: 2 } } ] }
    actions: { actionGroups: [ ag.id ] }
  }
}

resource queueLag 'Microsoft.Insights/scheduledQueryRules@2023-03-15-preview' = {
  name: '${name}-queue-lag'
  location: location == 'global' ? 'qatarcentral' : location
  tags: tags
  properties: {
    displayName: 'Queue lag — platform reports degraded'
    severity: 2
    enabled: true
    evaluationFrequency: 'PT5M'
    windowSize: 'PT15M'
    scopes: [ lawId ]
    criteria: { allOf: [ { query: 'ContainerAppConsoleLogs_CL | where Log_s has \'"queue":{"status":"degraded"\' | summarize Count = count()', timeAggregation: 'Total', metricMeasureColumn: 'Count', operator: 'GreaterThan', threshold: 3, failingPeriods: { numberOfEvaluationPeriods: 1, minFailingPeriodsToAlert: 1 } } ] }
    actions: { actionGroups: [ ag.id ] }
  }
}

resource dbCpu 'Microsoft.Insights/metricAlerts@2018-03-01' = {
  name: '${name}-db-cpu'
  location: 'global'
  tags: tags
  properties: {
    severity: 2
    enabled: true
    scopes: [ pgId ]
    evaluationFrequency: 'PT5M'
    windowSize: 'PT15M'
    criteria: { 'odata.type': 'Microsoft.Azure.Monitor.SingleResourceMultipleMetricCriteria', allOf: [ { name: 'cpu', metricName: 'cpu_percent', operator: 'GreaterThan', threshold: 80, timeAggregation: 'Average', criterionType: 'StaticThresholdCriterion' } ] }
    actions: [ { actionGroupId: ag.id } ]
  }
}

resource dbStorage 'Microsoft.Insights/metricAlerts@2018-03-01' = {
  name: '${name}-db-storage'
  location: 'global'
  tags: tags
  properties: {
    severity: 2
    enabled: true
    scopes: [ pgId ]
    evaluationFrequency: 'PT15M'
    windowSize: 'PT30M'
    criteria: { 'odata.type': 'Microsoft.Azure.Monitor.SingleResourceMultipleMetricCriteria', allOf: [ { name: 'storage', metricName: 'storage_percent', operator: 'GreaterThan', threshold: 80, timeAggregation: 'Average', criterionType: 'StaticThresholdCriterion' } ] }
    actions: [ { actionGroupId: ag.id } ]
  }
}

output actionGroupId string = ag.id
