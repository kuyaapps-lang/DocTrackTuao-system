$ErrorActionPreference = 'Stop'

$projectRoot = Get-Location
$documentsPath = Join-Path $projectRoot 'resources\js\pages\Documents.vue'
$detailsPath   = Join-Path $projectRoot 'resources\js\pages\DocumentDetails.vue'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'

function Replace-Required {
    param(
        [string]$Text,
        [string]$Old,
        [string]$New,
        [string]$Label
    )

    if (-not $Text.Contains($Old)) {
        throw "Could not find expected code for: $Label. No file was overwritten for that step."
    }

    return $Text.Replace($Old, $New)
}

if (-not (Test-Path $documentsPath)) {
    throw "Missing file: $documentsPath"
}

if (-not (Test-Path $detailsPath)) {
    throw "Missing file: $detailsPath"
}

Copy-Item $documentsPath "$documentsPath.process4d2-$stamp.bak"
Copy-Item $detailsPath "$detailsPath.process4d2-$stamp.bak"

# -----------------------------------------------------------------------------
# Documents.vue
# -----------------------------------------------------------------------------
$documents = Get-Content $documentsPath -Raw

$documents = Replace-Required $documents `
    "import { onMounted, ref } from 'vue'" `
    "import { computed, onMounted, ref } from 'vue'" `
    'Documents.vue Vue import'

$documents = Replace-Required $documents `
    "import { Input } from '@/components/ui/input'" `
    "import { Input } from '@/components/ui/input'`r`nimport { can } from '@/lib/auth'" `
    'Documents.vue auth import'

$old = @"
const getToken = () => {
    return localStorage.getItem('auth_token')
}
"@
$new = @"
const getToken = () => {
    return localStorage.getItem('auth_token')
}

const canCreateDocuments = computed(() => {
    return can('documents.create')
})
"@
$documents = Replace-Required $documents $old $new 'Documents.vue create permission'

$old = @"
const openCreateForm = async () => {
    resetForm()
"@
$new = @"
const openCreateForm = async () => {
    if (!canCreateDocuments.value) {
        return
    }

    resetForm()
"@
$documents = Replace-Required $documents $old $new 'Documents.vue open form guard'

$old = @"
const createDocument = async () => {
    createError.value = ''
"@
$new = @"
const createDocument = async () => {
    if (!canCreateDocuments.value) {
        createError.value =
            'You do not have permission to register documents.'
        return
    }

    createError.value = ''
"@
$documents = Replace-Required $documents $old $new 'Documents.vue create action guard'

$old = @"
            <Button
                @click="openCreateForm"
"@
$new = @"
            <Button
                v-if="canCreateDocuments"
                @click="openCreateForm"
"@
$documents = Replace-Required $documents $old $new 'Documents.vue Register button visibility'

$old = @"
        <div
            v-if="showCreateForm"
"@
$new = @"
        <div
            v-if="showCreateForm && canCreateDocuments"
"@
$documents = Replace-Required $documents $old $new 'Documents.vue Register modal visibility'

Set-Content -Path $documentsPath -Value $documents -Encoding UTF8

# -----------------------------------------------------------------------------
# DocumentDetails.vue
# -----------------------------------------------------------------------------
$details = Get-Content $detailsPath -Raw

$details = Replace-Required $details `
    "import { Button } from '@/components/ui/button'" `
    "import { Button } from '@/components/ui/button'`r`nimport { can } from '@/lib/auth'" `
    'DocumentDetails.vue auth import'

$old = @"
const canManageAttachments = computed(() => {
    return routingOptions.value?.can_act === true
})
"@
$new = @"
const canManageAttachments = computed(() => {
    return (
        can('attachments.manage') &&
        routingOptions.value?.can_act === true
    )
})
"@
$details = Replace-Required $details $old $new 'Attachment manage permission'

$old = @"
const canAccessAttachments = computed(() => {
    const userOfficeId =
"@
$new = @"
const canAccessAttachments = computed(() => {
    if (!can('attachments.view')) {
        return false
    }

    const userOfficeId =
"@
$details = Replace-Required $details $old $new 'Attachment view permission'

$old = @"
const canUpdateProcessing = computed(() => {
    return (
        processingInfo.value?.can_update ===
        true
    )
})
"@
$new = @"
const canUpdateProcessing = computed(() => {
    return (
        can('documents.process') &&
        processingInfo.value?.can_update === true
    )
})
"@
$details = Replace-Required $details $old $new 'Processing permission'

$old = @"
const canReceive = computed(() => {
    if (
        !pendingRoute.value ||
"@
$new = @"
const canReceive = computed(() => {
    if (
        !can('documents.route') ||
        !pendingRoute.value ||
"@
$details = Replace-Required $details $old $new 'Receive permission'

$old = @"
const canForward = computed(() => {
    if (
        !routingOptions.value?.can_act
"@
$new = @"
const canForward = computed(() => {
    if (
        !can('documents.route') ||
        !routingOptions.value?.can_act
"@
$details = Replace-Required $details $old $new 'Forward permission'

Set-Content -Path $detailsPath -Value $details -Encoding UTF8

Write-Host ''
Write-Host 'Process 4D2 frontend RBAC patch applied.' -ForegroundColor Green
Write-Host "Backups created with suffix: .process4d2-$stamp.bak" -ForegroundColor DarkGray
Write-Host ''
Write-Host 'Next run:' -ForegroundColor Cyan
Write-Host 'npm run build'
