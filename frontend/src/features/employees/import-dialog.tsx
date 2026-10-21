import { useState } from 'react'
import { CheckCircle2, Download, Loader2, TriangleAlert } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { errorMessage } from '@/lib/forms'
import { uploadEmployeeImport, useEmployeeImport } from './api'

// Mirrors EmployeeImportService::REQUIRED / OPTIONAL.
const TEMPLATE = [
  'employee_code,first_name,last_name,email,phone,job_title,employment_type,hire_date,department_code,location_code,group_codes',
  'E-001,Ayesha,Khan,ayesha@example.com,,Supervisor,full_time,2026-01-15,OPS,LHE,NIGHT;DRIVERS',
].join('\n')

function downloadTemplate() {
  const url = URL.createObjectURL(new Blob([TEMPLATE], { type: 'text/csv' }))
  const link = document.createElement('a')
  link.href = url
  link.download = 'employees-template.csv'
  link.click()
  URL.revokeObjectURL(url)
}

export function ImportEmployeesDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const [file, setFile] = useState<File | null>(null)
  const [importId, setImportId] = useState<number | null>(null)
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const run = useEmployeeImport(importId)
  const result = run.data
  const finished = result?.status === 'completed' || result?.status === 'failed'

  const reset = () => {
    setFile(null)
    setImportId(null)
    setUploadError(null)
  }

  const start = async () => {
    if (!file) return
    setUploading(true)
    setUploadError(null)
    try {
      setImportId((await uploadEmployeeImport(file)).id)
    } catch (error) {
      setUploadError(errorMessage(error))
    } finally {
      setUploading(false)
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) reset()
        onOpenChange(next)
      }}
    >
      <DialogContent className="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import employees</DialogTitle>
          <DialogDescription>
            Upload a CSV with a header row. Valid rows are created; rows with problems are skipped and listed below.
          </DialogDescription>
        </DialogHeader>

        {importId === null ? (
          <div className="grid gap-4">
            <div className="bg-muted/40 rounded-lg border p-3 text-sm">
              <p>
                Required columns:{' '}
                <span className="font-mono">employee_code, first_name, last_name, hire_date, department_code</span>. Optional:{' '}
                <span className="font-mono">email, phone, job_title, employment_type, location_code, group_codes</span> (separate
                several groups with <span className="font-mono">;</span>). Dates as YYYY-MM-DD. Up to 5,000 rows.
              </p>
              <Button variant="link" className="h-auto px-0" onClick={downloadTemplate}>
                <Download /> Download template
              </Button>
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="import-file">CSV file</Label>
              <Input
                id="import-file"
                type="file"
                accept=".csv,text/csv"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                aria-describedby={uploadError ? 'import-error' : undefined}
              />
              {uploadError && (
                <p id="import-error" className="text-destructive text-xs font-medium">
                  {uploadError}
                </p>
              )}
            </div>
          </div>
        ) : !finished ? (
          <div className="flex items-center gap-3 rounded-lg border p-4" role="status">
            <Loader2 className="text-muted-foreground size-5 animate-spin" />
            <p className="text-sm">Importing… this runs in the background, you can close this window.</p>
          </div>
        ) : (
          <div className="grid gap-4" role="status">
            <div className="flex items-start gap-3 rounded-lg border p-4">
              {result.status === 'completed' && result.failed_count === 0 ? (
                <CheckCircle2 className="text-status-present size-5" />
              ) : (
                <TriangleAlert className="text-status-late size-5" />
              )}
              <div className="text-sm">
                {result.status === 'failed' ? (
                  <p className="font-medium">The file could not be imported.</p>
                ) : (
                  <p className="font-medium">
                    {result.created_count} of {result.total_rows} rows imported
                    {result.failed_count > 0 ? `, ${String(result.failed_count)} skipped` : ''}.
                  </p>
                )}
              </div>
            </div>
            {result.errors.length > 0 && (
              <div className="max-h-72 overflow-auto rounded-md border">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-16">Line</TableHead>
                      <TableHead className="w-28">Code</TableHead>
                      <TableHead>Problem</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {result.errors.map((error) => (
                      <TableRow key={`${String(error.row)}-${error.code ?? ''}`}>
                        <TableCell className="tabular-nums">{error.row || '—'}</TableCell>
                        <TableCell className="font-mono text-xs">{error.code ?? '—'}</TableCell>
                        <TableCell className="text-sm whitespace-normal">{error.messages.join(' ')}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </div>
        )}

        <DialogFooter>
          {importId === null ? (
            <>
              <Button variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button onClick={() => void start()} disabled={!file || uploading}>
                {uploading && <Loader2 className="animate-spin" />}
                Import
              </Button>
            </>
          ) : (
            <>
              {finished && (
                <Button variant="outline" onClick={reset}>
                  Import another file
                </Button>
              )}
              <Button onClick={() => onOpenChange(false)}>Close</Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
