const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

function calculateCRC32(buf) {
    const crcTable = new Uint32Array(256);
    for (let i = 0; i < 256; i++) {
        let c = i;
        for (let k = 0; k < 8; k++) {
            c = ((c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1));
        }
        crcTable[i] = c;
    }
    let crc = 0xFFFFFFFF;
    for (let i = 0; i < buf.length; i++) {
        crc = (crc >>> 8) ^ crcTable[(crc ^ buf[i]) & 0xFF];
    }
    return (crc ^ 0xFFFFFFFF) >>> 0;
}

function createZip(files, outputPath) {
    const localHeaders = [];
    const centralHeaders = [];
    let offset = 0;

    for (const file of files) {
        const fileData = fs.readFileSync(file.src);
        const fileNameBuffer = Buffer.from(file.entryName.replace(/\\/g, '/'), 'utf8');

        const deflated = zlib.deflateRawSync(fileData);
        const crc = calculateCRC32(fileData);

        const localHeader = Buffer.alloc(30 + fileNameBuffer.length);
        localHeader.writeUInt32LE(0x04034b50, 0);
        localHeader.writeUInt16LE(20, 4);
        localHeader.writeUInt16LE(0x0800, 6);
        localHeader.writeUInt16LE(8, 8);
        localHeader.writeUInt16LE(0, 10);
        localHeader.writeUInt16LE(0, 12);
        localHeader.writeUInt32LE(crc, 14);
        localHeader.writeUInt32LE(deflated.length, 18);
        localHeader.writeUInt32LE(fileData.length, 22);
        localHeader.writeUInt16LE(fileNameBuffer.length, 26);
        localHeader.writeUInt16LE(0, 28);
        fileNameBuffer.copy(localHeader, 30);

        localHeaders.push(localHeader, deflated);

        const centralHeader = Buffer.alloc(46 + fileNameBuffer.length);
        centralHeader.writeUInt32LE(0x02014b50, 0);
        centralHeader.writeUInt16LE(20, 4);
        centralHeader.writeUInt16LE(20, 6);
        centralHeader.writeUInt16LE(0x0800, 8);
        centralHeader.writeUInt16LE(8, 10);
        centralHeader.writeUInt16LE(0, 12);
        centralHeader.writeUInt16LE(0, 14);
        centralHeader.writeUInt32LE(crc, 16);
        centralHeader.writeUInt32LE(deflated.length, 20);
        centralHeader.writeUInt32LE(fileData.length, 24);
        centralHeader.writeUInt16LE(fileNameBuffer.length, 28);
        centralHeader.writeUInt16LE(0, 30);
        centralHeader.writeUInt16LE(0, 32);
        centralHeader.writeUInt16LE(0, 34);
        centralHeader.writeUInt16LE(0, 36);
        centralHeader.writeUInt32LE(0, 38);
        centralHeader.writeUInt32LE(offset, 42);
        fileNameBuffer.copy(centralHeader, 46);

        centralHeaders.push(centralHeader);

        offset += localHeader.length + deflated.length;
    }

    const centralDirBuffer = Buffer.concat(centralHeaders);
    const centralDirOffset = offset;
    const centralDirSize = centralDirBuffer.length;

    const eocd = Buffer.alloc(22);
    eocd.writeUInt32LE(0x06054b50, 0);
    eocd.writeUInt16LE(0, 4);
    eocd.writeUInt16LE(0, 6);
    eocd.writeUInt16LE(files.length, 8);
    eocd.writeUInt16LE(files.length, 10);
    eocd.writeUInt32LE(centralDirSize, 12);
    eocd.writeUInt32LE(centralDirOffset, 16);
    eocd.writeUInt16LE(0, 20);

    const finalZipBuffer = Buffer.concat([...localHeaders, centralDirBuffer, eocd]);
    fs.writeFileSync(outputPath, finalZipBuffer);
    console.log(`[ZIP SUCCESS] Wrote ${finalZipBuffer.length} bytes to ${outputPath}`);
}

const root = path.resolve(__dirname);
const files = [
    { 
        src: path.join(root, 'staging', 'emko-site-tweaks', 'emko-site-tweaks.php'), 
        entryName: 'emko-site-tweaks/emko-site-tweaks.php' 
    }
];

const distDir = path.join(root, 'dist');
if (!fs.existsSync(distDir)) fs.mkdirSync(distDir, { recursive: true });

const distZip = path.join(distDir, 'emko-site-tweaks.zip');
createZip(files, distZip);
