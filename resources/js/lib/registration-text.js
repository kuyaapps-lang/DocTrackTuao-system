export const sanitizeRegistrationTitle = value => String(value || '').replace(/[^A-Za-z0-9 -]/g, '')

export const sanitizeRegistrationDescription = value => String(value || '').replace(/[^A-Za-z0-9 ,@#()/-]/g, '')
