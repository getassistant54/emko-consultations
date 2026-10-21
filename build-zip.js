const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

// Minimal standard ZIP generator with strictly Unix forward-slashes
function createZip(files, outputPath) {
    const localHeaders = [];
    const centralHeaders = [];
    let offset = 0;

    for (const file of files) {
        const fileData = fs.readFileSync(file.src);
        const fileNameBuffer = Buffer.from(file.entryName.replace(/\\/g, '/'), 'utf8');

        // Deflate
        const deflated = zlib.deflateRawSync(fileData);
        const crc = calculateCRC32(fileData);

        // Local file header (30 bytes + name)
        const localHeader = Buffer.alloc(30 + fileNameBuffer.length);
        localHeader.writeUInt32LE(0x04034b50, 0); // signature
        localHeader.writeUInt16LE(20, 4);         // version needed (2.0)
        localHeader.writeUInt16LE(0x0800, 6);     // flags (UTF-8)
        localHeader.writeUInt16LE(8, 8);          // compression method (deflate)
        localHeader.writeUInt16LE(0, 10);         // mod time
        localHeader.writeUInt16LE(0, 12);         // mod date
        localHeader.writeUInt32LE(crc, 14);       // crc-32
        localHeader.writeUInt32LE(deflated.length, 18); // compressed size
        localHeader.writeUInt32LE(fileData.length, 22); // uncompressed size
        localHeader.writeUInt16LE(fileNameBuffer.length, 26); // file name length
        localHeader.writeUInt16LE(0, 28);         // extra field length
        fileNameBuffer.copy(localHeader, 30);

        localHeaders.push(localHeader, deflated);

        // Central directory file header (46 bytes + name)
        const centralHeader = Buffer.alloc(46 + fileNameBuffer.length);
        centralHeader.writeUInt32LE(0x02014b50, 0); // signature
        centralHeader.writeUInt16LE(20, 4);          // version made by (UNIX 3.0 / DOS)
        centralHeader.writeUInt16LE(20, 6);          // version needed
        centralHeader.writeUInt16LE(0x0800, 8);      // flags (UTF-8)
        centralHeader.writeUInt16LE(8, 10);          // compression method (deflate)
        centralHeader.writeUInt16LE(0, 12);          // mod time
        centralHeader.writeUInt16LE(0, 14);          // mod date
        centralHeader.writeUInt32LE(crc, 16);        // crc-32
        centralHeader.writeUInt32LE(deflated.length, 20); // compressed size
        centralHeader.writeUInt32LE(fileData.length, 24); // uncompressed size
        centralHeader.writeUInt16LE(fileNameBuffer.length, 28); // file name length
        centralHeader.writeUInt16LE(0, 30);          // extra field length
        centralHeader.writeUInt16LE(0, 32);          // file comment length
        centralHeader.writeUInt16LE(0, 34);          // disk number start
        centralHeader.writeUInt16LE(0, 36);          // internal file attributes
        centralHeader.writeUInt32LE(0, 38);          // external file attributes
        centralHeader.writeUInt32LE(offset, 42);     // relative offset of local header
        fileNameBuffer.copy(centralHeader, 46);

        centralHeaders.push(centralHeader);

        offset += localHeader.length + deflated.length;
    }

    const centralDirBuffer = Buffer.concat(centralHeaders);
    const centralDirOffset = offset;
    const centralDirSize = centralDirBuffer.length;

    // End of central directory record (22 bytes)
    const eocd = Buffer.alloc(22);
    eocd.writeUInt32LE(0x06054b50, 0); // signature
    eocd.writeUInt16LE(0, 4);          // disk number
    eocd.writeUInt16LE(0, 6);          // disk with central dir
    eocd.writeUInt16LE(files.length, 8); // total entries on disk
    eocd.writeUInt16LE(files.length, 10); // total entries
    eocd.writeUInt32LE(centralDirSize, 12); // size of central dir
    eocd.writeUInt32LE(centralDirOffset, 16); // offset of central dir
    eocd.writeUInt16LE(0, 20);         // comment length

    const finalZipBuffer = Buffer.concat([...localHeaders, centralDirBuffer, eocd]);
    fs.writeFileSync(outputPath, finalZipBuffer);
    console.log(`[ZIP SUCCESS] Wrote ${finalZipBuffer.length} bytes to ${outputPath}`);
}

// CRC32 table
const crcTable = new Uint32Array(256);
for (let i = 0; i < 256; i++) {
    let c = i;
    for (let k = 0; k < 8; k++) {
        c = ((c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1));
    }
    crcTable[i] = c;
}

function calculateCRC32(buf) {
    let crc = 0xFFFFFFFF;
    for (let i = 0; i < buf.length; i++) {
        crc = (crc >>> 8) ^ crcTable[(crc ^ buf[i]) & 0xFF];
    }
    return (crc ^ 0xFFFFFFFF) >>> 0;
}

// Run build
const root = path.resolve(__dirname);
const files = [
    { src: path.join(root, 'emko-consultations.php'), entryName: 'emko-consultations/emko-consultations.php' },
    { src: path.join(root, 'emko-booking.php'), entryName: 'emko-consultations/emko-booking.php' },
    { src: path.join(root, 'assets', 'booking-widget.css'), entryName: 'emko-consultations/assets/booking-widget.css' },
    { src: path.join(root, 'assets', 'booking-widget.js'), entryName: 'emko-consultations/assets/booking-widget.js' },
    { src: path.join(root, 'includes', 'class-admin.php'), entryName: 'emko-consultations/includes/class-admin.php' },
    { src: path.join(root, 'includes', 'class-api.php'), entryName: 'emko-consultations/includes/class-api.php' },
    { src: path.join(root, 'includes', 'class-caldav.php'), entryName: 'emko-consultations/includes/class-caldav.php' },
    { src: path.join(root, 'includes', 'class-getcourse.php'), entryName: 'emko-consultations/includes/class-getcourse.php' }
];

const distDir = path.join(root, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

const distZip = path.join(distDir, 'emko-consultations.zip');
createZip(files, distZip);

