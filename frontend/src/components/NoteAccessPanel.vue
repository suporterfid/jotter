<template>
  <section class="note-access-panel" data-testid="note-access-panel">
    <div class="note-access-summary">
      <span class="note-access-status" :class="{ restricted: acl?.restricted }">
        {{ acl?.restricted ? t('noteEditor.restricted') : t('noteEditor.inheritedAccess') }}
      </span>
      <span v-if="acl?.restricted" class="note-access-capability">
        {{ acl?.can_edit ? t('noteEditor.canEdit') : t('noteEditor.readOnly') }}
      </span>
    </div>

    <p v-if="!canManage" class="note-access-help">{{ t('noteEditor.restrictionManagedByAdmin') }}</p>

    <template v-if="canManage">
      <div v-if="draft.length" class="note-access-entries" data-testid="note-access-entries">
        <div v-for="(entry, index) in draft" :key="`${entry.principal_type}-${entry.principal_id}`" class="note-access-entry">
          <span>{{ principalLabel(entry) }}</span>
          <span>{{ entry.permission === 'edit' ? t('noteEditor.canEdit') : t('noteEditor.canView') }}</span>
          <button type="button" class="btn-quiet" :aria-label="t('noteEditor.removeRestriction')" @click="removeEntry(index)">&times;</button>
        </div>
      </div>

      <div class="note-access-add">
        <select v-model="principalType" data-testid="note-access-principal-type" :aria-label="t('noteEditor.principalType')">
          <option value="group">{{ t('noteEditor.group') }}</option>
          <option value="user">{{ t('noteEditor.user') }}</option>
        </select>
        <select v-model="principalId" data-testid="note-access-principal" :aria-label="t('noteEditor.principal')">
          <option value="">{{ t('noteEditor.choosePrincipal') }}</option>
          <option v-for="principal in principals" :key="`${principalType}-${principal.id}`" :value="principal.id">
            {{ principal.name }}
          </option>
        </select>
        <select v-model="permission" data-testid="note-access-permission" :aria-label="t('noteEditor.permission')">
          <option value="view">{{ t('noteEditor.canView') }}</option>
          <option value="edit">{{ t('noteEditor.canEdit') }}</option>
        </select>
        <button type="button" class="btn-quiet" data-testid="note-access-add" :disabled="!principalId" @click="addEntry">{{ t('noteEditor.add') }}</button>
      </div>

      <button type="button" class="btn-primary" data-testid="note-access-save" :disabled="saving" @click="save">
        {{ saving ? t('noteEditor.saving') : t('noteEditor.saveRestrictions') }}
      </button>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getNoteAcl, getWorkspaceGroups, replaceNoteAcl } from '../services/api'
import type { NoteAccessMeta, NoteAccessPayload, NoteAclEntry, WorkspaceGroup } from '../services/types'

const props = defineProps<{
  workspaceId: number
  noteId: number
  access?: NoteAccessMeta | null
}>()

const emit = defineEmits<{ (event: 'updated', access: NoteAccessPayload): void }>()
const { t } = useI18n()
const acl = ref<NoteAccessPayload | null>(null)
const groups = ref<WorkspaceGroup[]>([])
const draft = ref<NoteAclEntry[]>([])
const principalType = ref<'user' | 'group'>('group')
const principalId = ref('')
const permission = ref<'view' | 'edit'>('view')
const saving = ref(false)

const canManage = computed(() => Boolean(props.access?.can_manage))
const principals = computed(() => {
  if (principalType.value === 'group') return groups.value.map((group) => ({ id: group.id, name: group.name }))
  const unique = new Map<number, { id: number; name: string }>()
  groups.value.flatMap((group) => group.members).forEach((member) => unique.set(member.id, { id: member.id, name: member.name }))
  return [...unique.values()]
})

onMounted(async () => {
  try {
    acl.value = await getNoteAcl(props.workspaceId, props.noteId)
    draft.value = acl.value.entries.map((entry) => ({ ...entry }))
    if (canManage.value) groups.value = await getWorkspaceGroups(props.workspaceId)
  } catch {
    acl.value = props.access ? { ...props.access, entries: [] } : null
  }
})

function principalLabel(entry: NoteAclEntry): string {
  return entry.principal?.name ?? `${entry.principal_type} #${entry.principal_id}`
}

function addEntry() {
  const id = Number(principalId.value)
  if (!id || draft.value.some((entry) => entry.principal_type === principalType.value && entry.principal_id === id)) return
  draft.value.push({ principal_type: principalType.value, principal_id: id, permission: permission.value })
  principalId.value = ''
}

function removeEntry(index: number) {
  draft.value.splice(index, 1)
}

async function save() {
  saving.value = true
  try {
    const updated = await replaceNoteAcl(props.workspaceId, props.noteId, draft.value)
    acl.value = updated
    draft.value = updated.entries.map((entry) => ({ ...entry }))
    emit('updated', updated)
  } finally {
    saving.value = false
  }
}
</script>
