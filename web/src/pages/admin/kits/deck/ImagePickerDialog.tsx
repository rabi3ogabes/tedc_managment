import MediaPickerDialog from './MediaPickerDialog'
import type { Asset } from '../types'

type Props = { kitId: string; initial?: 'ai' | 'library' | 'upload'; prompt?: string; canGenerate: boolean; onPick: (asset: Asset) => void; onClose: () => void }

/** The picture chooser (kept for the places that only deal with pictures): see MediaPickerDialog. */
export default function ImagePickerDialog(props: Props) {
  return <MediaPickerDialog {...props} kind="image" />
}
