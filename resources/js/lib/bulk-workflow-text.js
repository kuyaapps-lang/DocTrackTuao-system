export const sanitizeBulkWorkflowText = value => String(value || '').replace(/[^A-Za-z0-9 ()-]/g, '').slice(0, 30)
