import { AlertTriangle } from 'lucide-react'
import { Component, type ErrorInfo, type ReactNode } from 'react'
import { Button } from '@/components/ui'

type Props = { children: ReactNode; title: string; hint: string; retry: string }

/** Keeps a crash in one settings tab from taking down the others. */
export default class TabErrorBoundary extends Component<Props, { error: Error | null }> {
  state = { error: null as Error | null }

  static getDerivedStateFromError(error: Error) {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('Settings tab crashed', error, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children
    return (
      <div className="grid place-items-center rounded-2xl border border-red-200 bg-red-50/60 px-6 py-16 text-center">
        <span className="grid size-14 place-items-center rounded-2xl bg-red-100 text-red-600"><AlertTriangle className="size-7" /></span>
        <h3 className="mt-4 text-lg font-bold text-navy-900">{this.props.title}</h3>
        <p className="mt-1 max-w-sm text-sm text-slate-500">{this.props.hint}</p>
        <Button className="mt-5" variant="outline" onClick={() => this.setState({ error: null })}>{this.props.retry}</Button>
      </div>
    )
  }
}
