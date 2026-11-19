/* Local PDF optimisation: Smallest reduces image quality, never rasterises pages. */
'use strict';
importScripts('pdf-lib.min.js', 'pako.min.js');
const { PDFDocument, PDFDict, PDFArray, PDFRawStream, PDFName, PDFInvalidObject } = PDFLib;
const name = PDFName.of;
const LIMIT = 128 * 1024 * 1024;
// About 100 dpi for an A4 scan. Text/vector operators remain untouched.
const IMAGE_EDGE = 1280;
const JPEG_QUALITY = 0.42;

function imageSamples(raw, width, height, channels, params) {
    const row = width * channels;
    const predictor = params instanceof PDFDict ? Number(String(params.get(name('Predictor')) || 1)) : 1;
    if (predictor === 1) return raw.length === row * height ? raw : null;
    if (!(params instanceof PDFDict)) return null;
    const columns = Number(String(params.get(name('Columns')) || 1));
    const colors = Number(String(params.get(name('Colors')) || 1));
    const bits = Number(String(params.get(name('BitsPerComponent')) || 8));
    if (columns !== width || colors !== channels || bits !== 8) return null;
    if (predictor === 2 && raw.length === row * height) {
        const out = raw.slice();
        for (let y = 0; y < height; y++) for (let x = channels; x < row; x++) out[y * row + x] += out[y * row + x - channels];
        return out;
    }
    if (predictor < 10 || predictor > 15 || raw.length !== (row + 1) * height) return null;
    const out = new Uint8Array(row * height);
    for (let y = 0; y < height; y++) {
        const kind = raw[y * (row + 1)];
        if (kind > 4) return null;
        for (let x = 0; x < row; x++) {
            const i = y * row + x;
            const left = x >= channels ? out[i - channels] : 0;
            const above = y ? out[i - row] : 0;
            const corner = y && x >= channels ? out[i - row - channels] : 0;
            let prediction = 0;
            if (kind === 1) prediction = left;
            if (kind === 2) prediction = above;
            if (kind === 3) prediction = Math.floor((left + above) / 2);
            if (kind === 4) {
                const p = left + above - corner;
                const a = Math.abs(p - left), b = Math.abs(p - above), c = Math.abs(p - corner);
                prediction = a <= b && a <= c ? left : b <= c ? above : corner;
            }
            out[i] = raw[y * (row + 1) + 1 + x] + prediction;
        }
    }
    return out;
}

async function smallerImage(obj, context, budget) {
    const d = obj.dict;
    if (typeof OffscreenCanvas === 'undefined' || typeof createImageBitmap === 'undefined') return null;
    if (String(d.get(name('Subtype'))) !== '/Image') return null;
    if (['SMask', 'Mask', 'ImageMask', 'Decode', 'F', 'Alternates', 'OPI', 'SMaskInData'].some(k => d.has(name(k)))) return null;
    const color = String(d.lookup(name('ColorSpace')));
    if (!['/DeviceRGB', '/DeviceGray'].includes(color) || Number(String(d.get(name('BitsPerComponent')))) !== 8) return null;
    const width = Number(String(d.get(name('Width')))), height = Number(String(d.get(name('Height'))));
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1 || width * height > 16000000) return null;
    const channels = color === '/DeviceRGB' ? 3 : 1;
    if (width * height * 4 > budget.remaining) return null;
    const filter = String(d.get(name('Filter')));
    if (!['undefined', '/FlateDecode', '/Fl', '/DCTDecode', '/DCT'].includes(filter)) return null;
    budget.remaining -= width * height * 4;
    let bitmap;
    try {
        if (filter === '/DCTDecode' || filter === '/DCT') {
            // Custom JPEG colour transforms need the PDF renderer, not a browser decoder.
            if (d.has(name('DecodeParms'))) return null;
            bitmap = await createImageBitmap(new Blob([obj.contents], { type: 'image/jpeg' }));
            if (bitmap.width !== width || bitmap.height !== height) return null;
        } else {
            const raw = filter === 'undefined' ? obj.contents : inflateBounded(obj.contents, LIMIT);
            const samples = imageSamples(raw, width, height, channels, d.lookup(name('DecodeParms')));
            if (!samples) return null;
            const rgba = new Uint8ClampedArray(width * height * 4);
            for (let p = 0, i = 0; p < samples.length; p += channels, i += 4) {
                rgba[i] = samples[p]; rgba[i + 1] = samples[p + (channels === 3 ? 1 : 0)];
                rgba[i + 2] = samples[p + (channels === 3 ? 2 : 0)]; rgba[i + 3] = 255;
            }
            bitmap = await createImageBitmap(new ImageData(rgba, width, height));
        }
        const ratio = Math.min(1, IMAGE_EDGE / Math.max(width, height));
        const w = Math.max(1, Math.round(width * ratio)), h = Math.max(1, Math.round(height * ratio));
        const canvas = new OffscreenCanvas(w, h);
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(bitmap, 0, 0, w, h);
        const blob = await canvas.convertToBlob({ type: 'image/jpeg', quality: JPEG_QUALITY });
        if (blob.type !== 'image/jpeg') return null;
        const bytes = new Uint8Array(await blob.arrayBuffer());
        if (bytes.length >= obj.contents.length) return null;
        const next = d.clone(context);
        next.set(name('Width'), PDFLib.PDFNumber.of(w)); next.set(name('Height'), PDFLib.PDFNumber.of(h));
        next.set(name('ColorSpace'), name('DeviceRGB'));
        next.set(name('Filter'), name('DCTDecode'));
        next.delete(name('DecodeParms'));
        return PDFRawStream.of(next, bytes);
    } catch (_) { return null; }
    finally { if (bitmap) bitmap.close(); }
}
// Bound expansion of untrusted Flate streams, including compressed image data.
function inflateBounded(bytes, remaining) {
    const inflater = new pako.Inflate({ chunkSize: 65536 });
    const chunks = [];
    let length = 0;
    inflater.onData = chunk => {
        length += chunk.length;
        if (length > Math.min(64 * 1024 * 1024, remaining)) throw new Error('Expansion limit');
        chunks.push(chunk);
    };
    inflater.push(bytes, true);
    if (inflater.err || !inflater.ended) throw new Error('Invalid compressed stream');
    const output = new Uint8Array(length);
    let offset = 0;
    for (const chunk of chunks) { output.set(chunk, offset); offset += chunk.length; }
    return output;
}
function same(a, b) {
    return a.length === b.length && a.every((v, i) => v === b[i]);
}
function structural(obj) {
    return obj instanceof PDFRawStream && ['/ObjStm', '/XRef'].includes(String(obj.dict.get(name('Type'))));
}
function canonical(obj, omit = []) {
    if (obj instanceof PDFInvalidObject) throw new Error('Unrecognised PDF object');
    if (obj instanceof PDFDict) {
        if (obj.has(name('ByteRange')) || obj.has(name('Perms')) ||
            String(obj.get(name('FT'))) === '/Sig' || String(obj.get(name('Type'))) === '/Sig') {
            throw new Error('Signature or document permissions');
        }
        return '{' + obj.entries().filter(([k]) => !omit.includes(String(k)))
            .map(([k, v]) => [String(k), canonical(v)]).sort((a, b) => a[0].localeCompare(b[0]))
            .map(row => JSON.stringify(row)).join(',') + '}';
    }
    if (obj instanceof PDFArray) return '[' + obj.asArray().map(v => canonical(v)).join(',') + ']';
    return String(obj);
}
async function optimise(bytes, mode = 'smallest') {
    if (bytes.length < 10 * 1024 * 1024) return { reason: 'small' };
    if (bytes.length >= 36 * 1024 * 1024) return { reason: 'capacity' };
    // A rewrite discards revision history and invalidates signatures. Preserve those originals.
    const source = new TextDecoder('latin1').decode(bytes);
    if ((source.match(/%%EOF/g) || []).length !== 1) return { reason: 'protected' };
    const options = { updateMetadata: false, throwOnInvalidObject: true };
    const doc = await PDFDocument.load(bytes, options);
    if (doc.isEncrypted || doc.catalog.has(name('OutputIntents'))) return { reason: 'protected' };
    const records = new Map();
    let remaining = LIMIT;
    const imageBudget = { remaining: 512 * 1024 * 1024 };
    let lossyImages = 0;
    const masks = new Set();
    for (const [, object] of doc.context.enumerateIndirectObjects()) {
        if (object instanceof PDFRawStream) for (const key of ['SMask', 'Mask']) {
            if (object.dict.has(name(key))) masks.add(String(object.dict.get(name(key))));
        }
    }
    for (const [ref, obj] of doc.context.enumerateIndirectObjects()) {
        if (structural(obj)) continue;
        if (!(obj instanceof PDFRawStream)) {
            records.set(String(ref), { value: canonical(obj) });
            continue;
        }
        const dict = obj.dict;
        // Preserve archival conformance rather than change the PDF version underneath it.
        if (String(dict.get(name('Type'))) === '/Metadata') {
            const raw = dict.has(name('Filter')) ? PDFLib.decodePDFRawStream(obj).decode() : obj.contents;
            if (/pdfaid:|pdfaExtension:/i.test(new TextDecoder().decode(raw))) return { reason: 'protected' };
        }
        const filter = String(dict.get(name('Filter')));
        const plain = !dict.has(name('Filter')) && !dict.has(name('DecodeParms'));
        const flate = filter === '/FlateDecode' || filter === '/Fl';
        let raw = obj.contents;
        let compressed = null;
        if (!dict.has(name('F')) && (plain || flate)) {
            try {
                if (flate) raw = inflateBounded(obj.contents, remaining);
                if (raw.length > remaining) throw new Error('Expansion budget');
                remaining -= raw.length;
                // Recompress existing Flate streams too. Predictor bytes stay intact,
                // so images retain every original sample, including alpha masks.
                compressed = pako.deflate(raw, { level: 9 });
            } catch (_) { compressed = null; }
        }
        const changed = compressed && compressed.length < obj.contents.length;
        const image = mode === 'smallest' && !masks.has(String(ref)) ? await smallerImage(obj, doc.context, imageBudget) : null;
        if (image && image.contents.length < (changed ? compressed.length : obj.contents.length)) {
            // Only these explicitly selected image objects may differ. All text,
            // fonts, page operators, attachments and other objects stay exact.
            canonical(dict);
            records.set(String(ref), { value: canonical(image.dict, ['/Length']), bytes: image.contents, changed: false });
            doc.context.assign(ref, image);
            lossyImages++;
            continue;
        }
        records.set(String(ref), {
            value: canonical(dict, changed ? ['/Length', '/Filter'] : ['/Length']),
            bytes: changed ? raw : obj.contents, changed: !!changed
        });
        if (changed) {
            const next = dict.clone(doc.context);
            next.set(name('Filter'), name('FlateDecode'));
            doc.context.assign(ref, PDFRawStream.of(next, compressed));
        }
    }
    const output = await doc.save({ useObjectStreams: true, addDefaultPage: false, updateFieldAppearances: false });
    if (output.length >= bytes.length) return { reason: 'unchanged' };
    // Reopen and compare every original information-bearing object, not just page count/text.
    // This covers images, fonts, annotations, forms, outlines, attachments and metadata too.
    const verified = await PDFDocument.load(output, options);
    if (verified.getPageCount() !== doc.getPageCount()) throw new Error('Page count changed');
    for (const key of ['Root', 'Info', 'ID', 'Encrypt']) {
        if (canonical(doc.context.trailerInfo[key]) !== canonical(verified.context.trailerInfo[key])) {
            throw new Error('Trailer changed');
        }
    }
    const objects = new Map(verified.context.enumerateIndirectObjects().map(([ref, obj]) => [String(ref), obj]));
    for (const [ref, expected] of records) {
        const obj = objects.get(ref);
        if (!obj) throw new Error('Missing object');
        if (expected.bytes) {
            if (!(obj instanceof PDFRawStream)) throw new Error('Missing stream');
            if (canonical(obj.dict, expected.changed ? ['/Length', '/Filter'] : ['/Length']) !== expected.value) {
                throw new Error('Stream dictionary changed');
            }
            const actual = expected.changed ? pako.inflate(obj.contents) : obj.contents;
            if (!same(expected.bytes, actual)) throw new Error('Stream content changed');
        } else if (canonical(obj) !== expected.value) throw new Error('Object changed');
    }
    return { bytes: output, reason: 'compressed', lossyImages };
}
self.onmessage = async function (event) {
    try {
        const request = event.data;
        const result = await optimise(new Uint8Array(request.bytes || request), request.mode || 'smallest');
        self.postMessage(result, result.bytes ? [result.bytes.buffer] : []);
    } catch (_) {
        // Parsing, encryption, unsupported structures or failed preservation checks: original wins.
        self.postMessage({ reason: 'protected' });
    }
};
