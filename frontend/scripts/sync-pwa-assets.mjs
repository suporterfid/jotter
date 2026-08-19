import { copyFileSync, existsSync, mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const frontendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const projectRoot = resolve(frontendRoot, '..')
const sourceRoot = resolve(frontendRoot, 'public')
const deployRoot = resolve(projectRoot, 'public')
const assets = ['manifest.webmanifest', 'offline.html', 'service-worker.js']

mkdirSync(deployRoot, { recursive: true })

for (const asset of assets) {
  const source = resolve(sourceRoot, asset)
  const destination = resolve(deployRoot, asset)

  if (!existsSync(source)) {
    throw new Error(`Missing PWA source asset: ${source}`)
  }

  copyFileSync(source, destination)
}
