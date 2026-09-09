// Компактный QR-генератор (byte mode, ECC L, одиночный блок, версии 1–5).
// Подходит для коротких строк (до ~106 байт). Возвращает SVG-строку.

var GF_EXP = new Array(256), GF_LOG = new Array(256);
(function () {
  var x = 1;
  for (var i = 0; i < 255; i++) { GF_EXP[i] = x; GF_LOG[x] = i; x <<= 1; if (x & 0x100) x ^= 0x11d; }
  GF_EXP[255] = GF_EXP[0];
})();

function gmul(a, b) { return (a && b) ? GF_EXP[(GF_LOG[a] + GF_LOG[b]) % 255] : 0; }

function rsGenPoly(n) {
  var poly = [1];
  for (var i = 0; i < n; i++) {
    var next = new Array(poly.length + 1).fill(0);
    for (var j = 0; j < poly.length; j++) {
      next[j] ^= gmul(poly[j], GF_EXP[i]);
      next[j + 1] ^= poly[j];
    }
    poly = next;
  }
  return poly;
}

function rsEncode(data, ecCount) {
  var gen = rsGenPoly(ecCount);
  var res = new Array(data.length + ecCount).fill(0);
  for (var i = 0; i < data.length; i++) res[i] = data[i];
  for (var i = 0; i < data.length; i++) {
    var f = res[i];
    if (f === 0) continue;
    for (var j = 0; j < gen.length; j++) res[i + j] ^= gmul(gen[j], f);
  }
  return res.slice(data.length);
}

function toBytes(s) { return unescape(encodeURIComponent(s)).split('').map(function (c) { return c.charCodeAt(0); }); }

// Версии 1–5, ECC L: [dataCodewords, ecCodewords, byteCapacity]
var VERSION_INFO = {
  1: [19, 7, 17], 2: [34, 10, 32], 3: [55, 15, 53], 4: [80, 20, 78], 5: [108, 26, 106]
};

function pickVersion(byteLen) {
  for (var v = 1; v <= 5; v++) if (byteLen <= VERSION_INFO[v][2]) return v;
  return 5;
}

function pushBits(bits, val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >> i) & 1); }

function makeDataCodewords(bytes, dataCW) {
  var bits = [];
  pushBits(bits, 4, 4);            // byte mode
  pushBits(bits, bytes.length, 8); // char count (v1–9)
  for (var i = 0; i < bytes.length; i++) pushBits(bits, bytes[i], 8);
  var cap = dataCW * 8;
  var term = Math.min(4, cap - bits.length);
  for (var t = 0; t < term; t++) bits.push(0);
  while (bits.length % 8 !== 0) bits.push(0);
  var pad = [0xEC, 0x11], pi = 0;
  while (bits.length < cap) { pushBits(bits, pad[pi % 2], 8); pi++; }
  var cw = [];
  for (var k = 0; k < bits.length; k += 8) cw.push(parseInt(bits.slice(k, k + 8).join(''), 2));
  return cw;
}

function alignPositions(v) {
  if (v === 1) return [];
  if (v === 2) return [6, 18];
  if (v === 3) return [6, 22];
  if (v === 4) return [6, 26];
  return [6, 30];
}

function getBchDigit(d) { var n = 0; while (d !== 0) { n++; d >>>= 1; } return n; }
function getBchTypeInfo(data) {
  var g = 0x537, d = data << 10;
  while (getBchDigit(d) - getBchDigit(g) >= 0) d ^= g << (getBchDigit(d) - getBchDigit(g));
  return ((data << 10) | d) ^ 0x5412;
}
function testBit(bits, i) { return (bits & (1 << i)) !== 0; }

function qrMatrix(text) {
  var bytes = toBytes(text);
  var version = pickVersion(bytes.length);
  var info = VERSION_INFO[version];
  var dataCW = info[0], ecCW = info[1];
  var maskPattern = 0;

  var codewords = makeDataCodewords(bytes, dataCW);
  var ec = rsEncode(codewords, ecCW);
  var full = codewords.concat(ec);
  var size = 17 + 4 * version;

  var m = [];
  for (var r = 0; r < size; r++) m.push(new Array(size).fill(null));

  function placeFinder(r0, c0) {
    for (var dr = -1; dr <= 7; dr++) for (var dc = -1; dc <= 7; dc++) {
      var rr = r0 + dr, cc = c0 + dc;
      if (rr < 0 || rr >= size || cc < 0 || cc >= size) continue;
      var on = (dr >= 0 && dr <= 6 && (dc === 0 || dc === 6)) ||
               (dc >= 0 && dc <= 6 && (dr === 0 || dr === 6)) ||
               (dr >= 2 && dr <= 4 && dc >= 2 && dc <= 4);
      m[rr][cc] = on;
    }
  }
  placeFinder(0, 0); placeFinder(0, size - 7); placeFinder(size - 7, 0);

  for (var i = 8; i < size - 8; i++) {
    if (m[6][i] === null) m[6][i] = (i % 2 === 0);
    if (m[i][6] === null) m[i][6] = (i % 2 === 0);
  }

  var pos = alignPositions(version);
  for (var pr = 0; pr < pos.length; pr++) for (var pc = 0; pc < pos.length; pc++) {
    var r0 = pos[pr], c0 = pos[pc];
    if ((pr === 0 && pc === 0) || (pr === 0 && pc === pos.length - 1) || (pr === pos.length - 1 && pc === 0)) continue;
    for (var dr = -2; dr <= 2; dr++) for (var dc = -2; dc <= 2; dc++) {
      m[r0 + dr][c0 + dc] = (Math.abs(dr) === 2 || Math.abs(dc) === 2 || (dr === 0 && dc === 0));
    }
  }

  m[size - 8][8] = true;

  var fmt = getBchTypeInfo((1 << 3) | maskPattern);
  for (var fi = 0; fi < 15; fi++) {
    var mod = !testBit(fmt, fi);
    if (fi < 6) m[fi][8] = mod;
    else if (fi < 8) m[fi + 1][8] = mod;
    else m[size - 15 + fi][8] = mod;
    if (fi < 8) m[8][size - fi - 1] = mod;
    else if (fi < 9) m[8][15 - fi - 1 + 1] = mod;
    else m[8][15 - fi - 1] = mod;
  }
  m[size - 8][8] = !testBit(fmt, 0);

  var inc = -1, row = size - 1, bitIndex = 7, byteIndex = 0;
  for (var col = size - 1; col > 0; col -= 2) {
    if (col === 6) col--;
    while (true) {
      for (var c2 = 0; c2 < 2; c2++) {
        var cc = col - c2;
        if (m[row][cc] === null) {
          var darkB = false;
          if (byteIndex < full.length) darkB = ((full[byteIndex] >>> bitIndex) & 1) === 1;
          if (((row + cc) % 2) === 0) darkB = !darkB; // mask 0
          m[row][cc] = darkB;
          bitIndex--;
          if (bitIndex === -1) { byteIndex++; bitIndex = 7; }
        }
      }
      row += inc;
      if (row < 0 || row >= size) { row -= inc; inc = -inc; break; }
    }
  }

  return { size: size, matrix: m };
}

function qrSvg(text, moduleSize, margin) {
  moduleSize = moduleSize || 4;
  margin = margin || 4;
  var q = qrMatrix(text);
  var dim = (q.size + 2 * margin) * moduleSize;
  var rects = '';
  for (var r = 0; r < q.size; r++) for (var c = 0; c < q.size; c++) {
    if (q.matrix[r][c]) rects += '<rect x="' + ((c + margin) * moduleSize) + '" y="' + ((r + margin) * moduleSize) + '" width="' + moduleSize + '" height="' + moduleSize + '"/>';
  }
  return '<svg xmlns="http://www.w3.org/2000/svg" width="' + dim + '" height="' + dim + '" viewBox="0 0 ' + dim + ' ' + dim + '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">' + rects + '</g></svg>';
}
window.qrSvg = qrSvg;


