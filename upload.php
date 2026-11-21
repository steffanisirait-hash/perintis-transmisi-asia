<?php
/*
  upload.php - penyimpan data website PT Perintis Transmisi Asia

  Letakkan file ini di folder yang sama dengan index.html (file website).
  Fungsinya: menyimpan foto proyek tambahan (proyek-tambahan.json + folder foto-proyek)
  dan lokasi kantor (lokasi.json) langsung di hosting, setelah pemilik klik Upload di web.
  Folder website harus bisa ditulis (pengaturan bawaan hosting biasa seperti cPanel).
*/

ini_set('display_errors', '0');
@date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// SHA-256 dari kata sandi pemilik (bukan kata sandi aslinya).
$SANDI_HASH = '0d8b7ada63a486493a5751e7b05ff5f0dfc0babc7bb73e95b8c8b6366671c5c6';

$DIR       = __DIR__;
$FOTO_DIR  = $DIR . '/foto-proyek';
$JSON_FOTO = $DIR . '/proyek-tambahan.json';
$JSON_LOK  = $DIR . '/lokasi.json';
$HOST_PETA = array('google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl', 'goo.gl', 'share.google', 'g.co');

function keluar($data, $kode = 200) {
    http_response_code($kode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gagal($pesan, $kode = 400) {
    keluar(array('ok' => false, 'pesan' => $pesan), $kode);
}

function teks($v, $maks) {
    $v = trim(strip_tags((string)$v));
    if (function_exists('mb_substr')) {
        return mb_substr($v, 0, $maks, 'UTF-8');
    }
    return substr($v, 0, $maks);
}

function baca_foto($file) {
    if (!is_file($file)) {
        return array();
    }
    $d = json_decode((string)file_get_contents($file), true);
    if (is_array($d) && isset($d['foto']) && is_array($d['foto'])) {
        return array_values($d['foto']);
    }
    return array();
}

function tulis_json($file, $data) {
    $tmp = $file . '.tmp';
    $isi = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $isi, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $file);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gagal('Metode tidak didukung.', 405);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 14 * 1024 * 1024) {
    gagal('Data terlalu besar.', 413);
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    gagal('Data tidak valid.');
}

$sandi = isset($in['sandi']) ? (string)$in['sandi'] : '';
if (!hash_equals($SANDI_HASH, hash('sha256', $sandi))) {
    sleep(1);
    keluar(array('ok' => false, 'error' => 'sandi', 'pesan' => 'Sandi salah.'), 403);
}

$aksi = isset($in['aksi']) ? (string)$in['aksi'] : '';

// ---- cek koneksi + sandi ----
if ($aksi === 'cek') {
    keluar(array('ok' => true));
}

// ---- tambah foto proyek ----
if ($aksi === 'tambah_foto') {
    $kat   = teks(isset($in['kategori']) ? $in['kategori'] : '', 40);
    $judul = teks(isset($in['judul']) ? $in['judul'] : '', 80);
    $lok   = teks(isset($in['lokasi']) ? $in['lokasi'] : '', 100);
    if ($kat === '') {
        gagal('Nama kategori belum diisi.');
    }
    if ($judul === '') {
        $judul = 'Dokumentasi Proyek';
    }
    $list = (isset($in['gambar']) && is_array($in['gambar'])) ? $in['gambar'] : array();
    if (count($list) < 1 || count($list) > 10) {
        gagal('Jumlah foto tidak sesuai.');
    }
    if (!is_dir($FOTO_DIR)) {
        if (!mkdir($FOTO_DIR, 0755, true)) {
            gagal('Server tidak bisa membuat folder foto. Cek izin folder.', 500);
        }
        @file_put_contents($FOTO_DIR . '/index.html', '');
    }

    $data = baca_foto($JSON_FOTO);
    $simpan = 0;
    foreach ($list as $uri) {
        if (!is_string($uri) || strncmp($uri, 'data:image/', 11) !== 0) {
            continue;
        }
        $pos = strpos($uri, ';base64,');
        if ($pos === false) {
            continue;
        }
        $bin = base64_decode(substr($uri, $pos + 8), true);
        if ($bin === false || strlen($bin) > 3 * 1024 * 1024) {
            continue;
        }
        $info = @getimagesizefromstring($bin);
        if (!$info || ($info[2] !== IMAGETYPE_JPEG && $info[2] !== IMAGETYPE_PNG)) {
            continue;
        }
        $ext = ($info[2] === IMAGETYPE_PNG) ? 'png' : 'jpg';
        do {
            $nama = 'foto-' . date('YmdHis') . '-' . dechex(mt_rand(4096, 65535)) . dechex(mt_rand(4096, 65535)) . '.' . $ext;
        } while (file_exists($FOTO_DIR . '/' . $nama));
        if (file_put_contents($FOTO_DIR . '/' . $nama, $bin) === false) {
            continue;
        }
        $data[] = array(
            'kategori' => $kat,
            'judul'    => $judul,
            'lokasi'   => $lok,
            'gambar'   => 'foto-proyek/' . $nama
        );
        $simpan++;
    }
    if ($simpan === 0) {
        gagal('Tidak ada foto yang valid (gunakan foto JPG atau PNG).');
    }
    if (!tulis_json($JSON_FOTO, array('foto' => $data))) {
        gagal('Server tidak bisa menyimpan data. Cek izin folder.', 500);
    }
    keluar(array('ok' => true, 'tersimpan' => $simpan, 'foto' => $data));
}

// ---- hapus foto proyek ----
if ($aksi === 'hapus_foto') {
    $idx  = isset($in['index']) ? (int)$in['index'] : -1;
    $data = baca_foto($JSON_FOTO);
    if ($idx < 0 || $idx >= count($data)) {
        gagal('Foto tidak ditemukan.');
    }
    $g = isset($data[$idx]['gambar']) ? (string)$data[$idx]['gambar'] : '';
    if (preg_match('#^foto-proyek/[A-Za-z0-9._-]+$#', $g) && strpos($g, '..') === false) {
        $p = $DIR . '/' . $g;
        if (is_file($p)) {
            @unlink($p);
        }
    }
    array_splice($data, $idx, 1);
    if (!tulis_json($JSON_FOTO, array('foto' => $data))) {
        gagal('Server tidak bisa menyimpan data. Cek izin folder.', 500);
    }
    keluar(array('ok' => true, 'foto' => $data));
}

// ---- simpan lokasi kantor ----
if ($aksi === 'simpan_lokasi') {
    $nama   = teks(isset($in['nama']) ? $in['nama'] : '', 100);
    $alamat = teks(isset($in['alamat']) ? $in['alamat'] : '', 250);
    if ($nama === '') {
        $nama = 'PT PERINTIS TRANSMISI ASIA';
    }
    if ($alamat === '') {
        gagal('Alamat tidak boleh kosong.');
    }
    $link = trim(isset($in['linkMaps']) ? (string)$in['linkMaps'] : '');
    if ($link !== '') {
        $p = parse_url($link);
        if (!$p || !isset($p['scheme']) || !isset($p['host'])
            || strtolower($p['scheme']) !== 'https'
            || !in_array(strtolower($p['host']), $HOST_PETA, true)) {
            $link = '';
        }
    }
    if (!tulis_json($JSON_LOK, array('nama' => $nama, 'alamat' => $alamat, 'linkMaps' => $link))) {
        gagal('Server tidak bisa menyimpan data. Cek izin folder.', 500);
    }
    keluar(array('ok' => true));
}

gagal('Aksi tidak dikenal.');
