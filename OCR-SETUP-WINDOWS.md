# OCR setup on Windows / XAMPP

The uploader does not require PHP `ZipArchive`.

For scanned PDFs and image-only question banks, install:

1. **Tesseract OCR** and language data:
   - `eng`
   - `tam`
   - `hin`
   - `fra`
2. **Poppler for Windows** so `pdftoppm.exe` is available for scanned PDF page conversion.
3. Add the folders containing `tesseract.exe`, `pdftotext.exe`, and `pdftoppm.exe` to the Windows PATH used by Apache/XAMPP.

Restart Apache after changing PATH.

## Verify from Command Prompt

```text
tesseract --version
tesseract --list-langs
pdftotext -v
pdftoppm -v
```

The application checks these commands during extraction.

If PATH is not possible, set Windows environment variables before Apache starts:

```text
QPS_TESSERACT=C:\path\to\tesseract.exe
QPS_PDFTOTEXT=C:\path\to\pdftotext.exe
QPS_PDFTOPPM=C:\path\to\pdftoppm.exe
```

## Language selection

For DOCX/XLSX/CSV/JSON/ODT/TXT, OCR is normally not used, so Unicode text such as Tamil, Hindi and French is preserved directly.

For scanned PDFs/images, select the OCR language that matches the document.

Examples:
- Tamil paper → `tam`
- Hindi paper → `hin`
- French paper → `fra`
- English + Tamil → `eng+tam`

## Equations

A native Word equation is preferable because the DOCX importer can read Office Math (OMML) and convert common equation structures to LaTeX.

For an equation that exists only as pixels in a scan, OCR may identify ordinary mathematical characters but cannot guarantee exact LaTeX reconstruction. The importer therefore preserves the scanned source/page image with extracted questions where possible, so the visual equation is not lost.
