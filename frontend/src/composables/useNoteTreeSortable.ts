import Sortable from 'sortablejs'

export type SortItem = { type: 'note'; id: number } | { type: 'folder'; path: string }

export function parseSortItemFromDataset(dataset: DOMStringMap): SortItem {
  if (dataset.itemType === 'folder') {
    return { type: 'folder', path: dataset.itemPath ?? '' }
  }
  return { type: 'note', id: Number(dataset.itemId) }
}

export function itemsFromContainer(container: HTMLElement): SortItem[] {
  return Array.from(container.children).map((el) =>
    parseSortItemFromDataset((el as HTMLElement).dataset)
  )
}

export function basename(path: string): string {
  const lastSlash = path.lastIndexOf('/')
  return lastSlash === -1 ? path : path.slice(lastSlash + 1)
}

export function pathInFolder(folderPath: string, fileName: string): string {
  return folderPath === '' ? fileName : `${folderPath}/${fileName}`
}

export function shouldRejectMove(draggedType: string | undefined, sameContainer: boolean): boolean {
  return draggedType === 'folder' && !sameContainer
}

export interface NoteTreeSortableCallbacks {
  onReorder: (folderPath: string, items: SortItem[]) => void
  onReparentNote: (noteId: number, newPath: string, destFolderPath: string, items: SortItem[]) => void
}

export interface NoteTreeSortableHandle {
  setDisabled: (disabled: boolean) => void
  destroy: () => void
}

export function createNoteTreeSortable(
  el: HTMLElement,
  initiallyDisabled: boolean,
  callbacks: NoteTreeSortableCallbacks,
): NoteTreeSortableHandle {
  const sortable = Sortable.create(el, {
    group: 'note-tree',
    disabled: initiallyDisabled,
    animation: 150,
    ghostClass: 'note-tree-ghost',
    onMove(evt) {
      const draggedType = (evt.dragged as HTMLElement).dataset.itemType
      return !shouldRejectMove(draggedType, evt.from === evt.to)
    },
    onEnd(evt) {
      const to = evt.to
      const from = evt.from
      const toFolderPath = to.dataset.folderPath ?? ''

      if (from === to) {
        callbacks.onReorder(toFolderPath, itemsFromContainer(to))
        return
      }

      const item = evt.item as HTMLElement
      const noteId = Number(item.dataset.itemId)
      const notePath = item.dataset.itemNotePath ?? ''
      const newPath = pathInFolder(toFolderPath, basename(notePath))
      callbacks.onReparentNote(noteId, newPath, toFolderPath, itemsFromContainer(to))
    },
  })

  return {
    setDisabled: (disabled: boolean) => sortable.option('disabled', disabled),
    destroy: () => sortable.destroy(),
  }
}
