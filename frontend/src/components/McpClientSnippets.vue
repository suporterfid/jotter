<template>
  <div class="mcp-snippets" data-testid="mcp-snippets">
    <p class="mcp-snippets-intro">{{ t('mcp.intro', { url: mcpUrl }) }}</p>
    <div class="mcp-snippets-tabs" role="tablist">
      <button
        v-for="client in clients"
        :key="client.id"
        type="button"
        role="tab"
        :aria-selected="active === client.id"
        :class="['mcp-snippets-tab', { active: active === client.id }]"
        :data-testid="`mcp-tab-${client.id}`"
        @click="active = client.id"
      >
        {{ client.label }}
      </button>
    </div>
    <div v-for="client in clients" :key="`panel-${client.id}`" v-show="active === client.id" role="tabpanel" class="mcp-snippet-panel">
      <div v-for="block in client.blocks" :key="block.title" class="mcp-snippet-block">
        <div class="mcp-snippet-header">
          <span class="mcp-snippet-title">{{ block.title }}</span>
          <button type="button" class="secondary-btn mcp-copy-btn" :data-testid="`mcp-copy-${client.id}-${block.kind}`" @click="copy(block.code, `${client.id}-${block.kind}`)">
            {{ copied === `${client.id}-${block.kind}` ? t('mcp.copied') : t('mcp.copy') }}
          </button>
        </div>
        <pre class="mcp-snippet-code" :data-testid="`mcp-code-${client.id}-${block.kind}`"><code>{{ block.code }}</code></pre>
      </div>
      <p v-if="client.note" class="mcp-snippet-note">{{ client.note }}</p>
    </div>
    <p class="mcp-snippets-smoke">{{ t('mcp.smokeTest') }}</p>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = withDefaults(defineProps<{
  token: string
  host?: string
  serverName?: string
}>(), {
  host: '',
  serverName: 'jotter',
})

type ClientId = 'claude-code' | 'cursor' | 'claude-desktop'

interface SnippetBlock {
  kind: 'cli' | 'json'
  title: string
  code: string
}

interface ClientSnippet {
  id: ClientId
  label: string
  blocks: SnippetBlock[]
  note?: string
}

const active = ref<ClientId>('claude-code')
const copied = ref<string | null>(null)

const origin = computed(() => {
  if (props.host) return props.host.replace(/\/+$/, '')
  return typeof window !== 'undefined' ? window.location.origin : 'https://<host>'
})

const mcpUrl = computed(() => `${origin.value}/api/mcp`)
const authHeader = computed(() => `Bearer ${props.token}`)
const isHttp = computed(() => mcpUrl.value.startsWith('http://'))

function json(value: unknown): string {
  return JSON.stringify(value, null, 2)
}

const clients = computed<ClientSnippet[]>(() => {
  const name = props.serverName
  const desktopArgs = ['-y', 'mcp-remote', mcpUrl.value, '--header', `Authorization:\${JOTTER_AUTH}`]
  if (isHttp.value) desktopArgs.push('--allow-http')

  return [
    {
      id: 'claude-code',
      label: 'Claude Code',
      blocks: [
        {
          kind: 'cli',
          title: t('mcp.claudeCodeCli'),
          code: `claude mcp add --transport http ${name} ${mcpUrl.value} \\\n  --header "Authorization: ${authHeader.value}"`,
        },
        {
          kind: 'json',
          title: '.mcp.json',
          code: json({ mcpServers: { [name]: { type: 'http', url: mcpUrl.value, headers: { Authorization: authHeader.value } } } }),
        },
      ],
    },
    {
      id: 'cursor',
      label: 'Cursor',
      blocks: [
        {
          kind: 'json',
          title: '.cursor/mcp.json',
          code: json({ mcpServers: { [name]: { url: mcpUrl.value, headers: { Authorization: authHeader.value } } } }),
        },
      ],
    },
    {
      id: 'claude-desktop',
      label: 'Claude Desktop',
      blocks: [
        {
          kind: 'json',
          title: 'claude_desktop_config.json',
          code: json({
            mcpServers: {
              [name]: {
                command: 'npx',
                args: desktopArgs,
                env: { JOTTER_AUTH: authHeader.value },
              },
            },
          }),
        },
      ],
      note: t('mcp.claudeDesktopNote'),
    },
  ]
})

async function copy(code: string, key: string) {
  try {
    await navigator.clipboard.writeText(code)
    copied.value = key
    setTimeout(() => {
      if (copied.value === key) copied.value = null
    }, 2000)
  } catch {
    copied.value = null
  }
}
</script>

<style scoped>
.mcp-snippets {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.mcp-snippets-intro,
.mcp-snippets-smoke,
.mcp-snippet-note {
  margin: 0;
  font-size: 0.8125rem;
  color: var(--color-text-muted);
}

.mcp-snippets-tabs {
  display: flex;
  gap: var(--space-1);
  border-bottom: 1px solid var(--color-border);
}

.mcp-snippets-tab {
  padding: var(--space-1) var(--space-3);
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
  font: inherit;
}

.mcp-snippets-tab.active {
  color: var(--color-text);
  border-bottom-color: var(--color-action);
}

.mcp-snippet-panel {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.mcp-snippet-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
}

.mcp-snippet-title {
  font-size: 0.8125rem;
  font-weight: 600;
}

.mcp-snippet-code {
  margin: 0;
  padding: var(--space-3);
  overflow-x: auto;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-bg-surface);
  font-family: var(--font-code);
  font-size: 0.75rem;
  line-height: 1.5;
  white-space: pre;
}
</style>
