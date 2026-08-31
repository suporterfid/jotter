import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import McpClientSnippets from './components/McpClientSnippets.vue'

const TOKEN = 'jt_mkt_abc123'

describe('McpClientSnippets', () => {
  it('fills the current host into the Claude Code command and .mcp.json', () => {
    const wrapper = mount(McpClientSnippets, { props: { token: TOKEN, host: 'https://acme.cadernia.app', serverName: 'jotter-acme' } })

    const cli = wrapper.find('[data-testid="mcp-code-claude-code-cli"]').text()
    expect(cli).toContain('claude mcp add --transport http jotter-acme https://acme.cadernia.app/api/mcp')
    expect(cli).toContain(`--header "Authorization: Bearer ${TOKEN}"`)

    const json = JSON.parse(wrapper.find('[data-testid="mcp-code-claude-code-json"]').text())
    expect(json.mcpServers['jotter-acme']).toEqual({
      type: 'http',
      url: 'https://acme.cadernia.app/api/mcp',
      headers: { Authorization: `Bearer ${TOKEN}` },
    })
  })

  it('renders Cursor and Claude Desktop (mcp-remote) configurations', async () => {
    const wrapper = mount(McpClientSnippets, { props: { token: TOKEN, host: 'https://acme.cadernia.app' } })

    await wrapper.find('[data-testid="mcp-tab-cursor"]').trigger('click')
    const cursor = JSON.parse(wrapper.find('[data-testid="mcp-code-cursor-json"]').text())
    expect(cursor.mcpServers.jotter.url).toBe('https://acme.cadernia.app/api/mcp')
    expect(cursor.mcpServers.jotter.headers.Authorization).toBe(`Bearer ${TOKEN}`)
    expect(cursor.mcpServers.jotter.type).toBeUndefined()

    await wrapper.find('[data-testid="mcp-tab-claude-desktop"]').trigger('click')
    const desktop = JSON.parse(wrapper.find('[data-testid="mcp-code-claude-desktop-json"]').text())
    expect(desktop.mcpServers.jotter.command).toBe('npx')
    expect(desktop.mcpServers.jotter.args).toEqual(['-y', 'mcp-remote', 'https://acme.cadernia.app/api/mcp', '--header', 'Authorization:${JOTTER_AUTH}'])
    expect(desktop.mcpServers.jotter.env.JOTTER_AUTH).toBe(`Bearer ${TOKEN}`)
    expect(wrapper.text()).toContain('mcp-remote')
  })

  it('adds --allow-http for plain-http development hosts and falls back to window.location.origin', () => {
    const wrapper = mount(McpClientSnippets, { props: { token: TOKEN, host: 'http://localhost:8080' } })
    const desktop = JSON.parse(wrapper.find('[data-testid="mcp-code-claude-desktop-json"]').text())
    expect(desktop.mcpServers.jotter.args).toContain('--allow-http')

    const fallback = mount(McpClientSnippets, { props: { token: TOKEN } })
    expect(fallback.find('[data-testid="mcp-code-claude-code-cli"]').text()).toContain(`${window.location.origin}/api/mcp`)
  })
})
