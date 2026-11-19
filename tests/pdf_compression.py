"""Run against local XAMPP: pip install playwright pypdf pymupdf.
Synthetic files and an isolated browser; no paper submissions or database writes.
"""
import io
import tempfile
import zlib
import random
import hashlib
from PIL import Image
from pathlib import Path
import pymupdf as fitz
from pypdf import PdfWriter
from pypdf.generic import DictionaryObject as Dict, NameObject as Name, NumberObject as Num, DecodedStreamObject, EncodedStreamObject
from playwright.sync_api import sync_playwright


def fixture(signed=False, encrypted=False, flate=False, jpeg=False):
    writer = PdfWriter()
    page = writer.add_blank_page(612, 792)
    image = DecodedStreamObject()
    image.set_data(bytes([120, 180, 220]) * (2048 * 2048))
    image.update({Name('/Type'): Name('/XObject'), Name('/Subtype'): Name('/Image'),
                  Name('/Width'): Num(2048), Name('/Height'): Num(2048),
                  Name('/ColorSpace'): Name('/DeviceRGB'), Name('/BitsPerComponent'): Num(8)})
    if flate:
        encoded = EncodedStreamObject()
        encoded.update(image)
        encoded[Name('/Filter')] = Name('/FlateDecode')
        encoded._data = zlib.compress(image.get_data(), level=0)
        image = encoded
    if jpeg:
        encoded = EncodedStreamObject()
        encoded.update(image)
        encoded[Name('/Width')] = Num(3200)
        encoded[Name('/Height')] = Num(3200)
        encoded[Name('/Filter')] = Name('/DCTDecode')
        photo = Image.frombytes('RGB', (3200, 3200), random.Random(2026).randbytes(3200 * 3200 * 3))
        buffer = io.BytesIO()
        photo.save(buffer, format='JPEG', quality=98)
        encoded._data = buffer.getvalue()
        image = encoded
    font = Dict({Name('/Type'): Name('/Font'), Name('/Subtype'): Name('/Type1'), Name('/BaseFont'): Name('/Helvetica')})
    page[Name('/Resources')] = Dict({Name('/XObject'): Dict({Name('/Im0'): writer._add_object(image)}), Name('/Font'): Dict({Name('/F1'): writer._add_object(font)})})
    content = DecodedStreamObject()
    content.set_data(b'q 200 0 0 200 40 400 cm /Im0 Do Q BT /F1 18 Tf 40 650 Td (Lossless thesis: text and figure) Tj ET')
    page[Name('/Contents')] = writer._add_object(content)
    writer.add_metadata({'/Title': 'Preserve metadata', '/Author': 'Synthetic test'})
    writer.add_attachment('evidence.txt', b'Attachment must survive exactly.')
    writer.add_outline_item('Figure and text', 0)
    if signed:
        writer._root_object[Name('/Perms')] = Dict({Name('/DocMDP'): writer._add_object(Dict({Name('/Type'): Name('/Sig')}))})
    if encrypted:
        writer.encrypt('test-password')
    out = io.BytesIO()
    writer.write(out)
    return out.getvalue()


def main():
    with tempfile.TemporaryDirectory(prefix='papel-compression-') as tmp, sync_playwright() as pw:
        folder = Path(tmp)
        original = fixture()
        files = {'large.pdf': original, 'flate.pdf': fixture(flate=True), 'signed.pdf': fixture(signed=True),
                 'photo.pdf': fixture(jpeg=True),
                 'encrypted.pdf': fixture(encrypted=True), 'revision.pdf': original + b'\n%%EOF\n',
                 'malformed.pdf': b'%PDF-1.7\n' + b'x' * (11 * 1048576) + b'\n%%EOF',
                 'boundary.pdf': b'%PDF-' + b'x' * (10 * 1048576 - 5)}
        for filename, data in files.items():
            (folder / filename).write_bytes(data)
        browser = pw.chromium.launch(executable_path=r'C:\Program Files\Google\Chrome\Application\chrome.exe', headless=True)
        page = browser.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.route('**/compression-fixture*', lambda route: route.fulfill(content_type='text/html', headers={
            'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'"
        }, body='<form id="uploadForm"><div><input type="file" name="pdf_full" id="pdf"></div><button>Submit</button></form><script data-mode="' + ('smallest' if 'smallest' in route.request.url else 'lossless') + '" src="/capstone/assests/js/papel-pdf-compressor.js"></script>'))
        page.goto('http://localhost/capstone/compression-fixture')
        page.wait_for_function('() => !!window.papelPdfCompressor')
        page.evaluate('window.papelAlert = message => window.lastAlert = message')
        page.locator('#pdf').set_input_files(folder / 'large.pdf')
        page.evaluate("document.querySelector('form').requestSubmit()")
        assert 'still being compressed' in page.evaluate('window.lastAlert')
        page.wait_for_function('() => !papelPdfCompressor.pending()', timeout=70000)
        assert 'Losslessly compressed:' in page.locator('.pdf-compression-status').inner_text()
        output = bytes(page.evaluate("async () => Array.from(new Uint8Array(await new FormData(document.querySelector('form')).get('pdf_full').arrayBuffer()))"))
        assert len(output) < len(original)
        a, b = fitz.open(stream=original, filetype='pdf'), fitz.open(stream=output, filetype='pdf')
        assert len(a) == len(b) and a.get_toc() == b.get_toc()
        assert {k:v for k,v in a.metadata.items() if k != 'format'} == {k:v for k,v in b.metadata.items() if k != 'format'}
        assert a[0].get_text() == b[0].get_text()
        assert a[0].get_pixmap().samples == b[0].get_pixmap().samples
        assert a.embfile_get(0) == b.embfile_get(0)
        print('PASS: picker, submit guard, FormData, identical pixels/text/metadata/bookmark/attachment:', len(original), '->', len(output))
        page.locator('#pdf').set_input_files(folder / 'flate.pdf')
        page.wait_for_function('() => !papelPdfCompressor.pending()', timeout=70000)
        output = bytes(page.evaluate("async () => Array.from(new Uint8Array(await document.querySelector('#pdf').files[0].arrayBuffer()))"))
        assert len(output) < len(files['flate.pdf']) / 2
        recompressed = fitz.open(stream=output, filetype='pdf')
        assert a[0].get_pixmap().samples == recompressed[0].get_pixmap().samples
        assert a[0].get_text() == recompressed[0].get_text()
        assert a.embfile_get(0) == recompressed.embfile_get(0)
        print('PASS: existing Flate stream recompressed, identical rendered pixels/text/attachment:', len(files['flate.pdf']), '->', len(output))
        for filename in ['signed.pdf', 'encrypted.pdf', 'revision.pdf', 'malformed.pdf', 'boundary.pdf']:
            page.locator('#pdf').set_input_files(folder / filename)
            page.wait_for_function('() => !papelPdfCompressor.pending()', timeout=70000)
            assert page.locator('#pdf').evaluate('(el) => el.files[0].size') == len(files[filename])
            print('PASS: original preserved:', filename)
        page.locator('#pdf').set_input_files(folder / 'large.pdf')
        page.locator('#pdf').set_input_files(folder / 'boundary.pdf')
        page.wait_for_timeout(1500)
        assert page.locator('#pdf').evaluate('(el) => el.files[0].name') == 'boundary.pdf'
        page.goto('http://localhost/capstone/compression-fixture?smallest')
        page.wait_for_function('() => !!window.papelPdfCompressor')
        assert len(files['photo.pdf']) > 10 * 1048576
        page.locator('#pdf').set_input_files(folder / 'photo.pdf')
        page.wait_for_function('() => !papelPdfCompressor.pending()', timeout=70000)
        assert 'Smallest mode:' in page.locator('.pdf-compression-status').inner_text()
        assert 'Image quality reduced' in page.locator('.pdf-compression-status').inner_text()
        output = bytes(page.evaluate("async () => Array.from(new Uint8Array(await new FormData(document.querySelector('form')).get('pdf_full').arrayBuffer()))"))
        assert len(output) < len(files['photo.pdf']) * 0.5
        photo_before = fitz.open(stream=files['photo.pdf'], filetype='pdf')
        photo_after = fitz.open(stream=output, filetype='pdf')
        assert photo_before[0].get_text() == photo_after[0].get_text()
        assert photo_before[0].rect == photo_after[0].rect
        assert photo_before.get_toc() == photo_after.get_toc()
        assert photo_before.embfile_get(0) == photo_after.embfile_get(0)
        assert max(photo_after[0].get_images()[0][2:4]) == 1280
        assert photo_after[0].get_pixmap().samples != photo_before[0].get_pixmap().samples
        print('PASS: Smallest JPEG compression, preserved selectable text/layout/bookmark/attachment:', len(files['photo.pdf']), '->', len(output))
        page.get_by_role('button', name='Use original').click()
        restored = page.evaluate("async () => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await document.querySelector('#pdf').files[0].arrayBuffer())), n => n.toString(16).padStart(2, '0')).join('')")
        assert restored == hashlib.sha256(files['photo.pdf']).hexdigest()
        assert not page.evaluate('papelPdfCompressor.pending()')
        print('PASS: Use original restores exact input bytes without recompressing')
        assert not errors, errors
        print('PASS: replacement race and CSP; no browser errors')
        browser.close()


if __name__ == '__main__':
    main()
