-- Skema Database KosLink V2 (versi perbaikan)
-- Jalankan file ini sekali (phpMyAdmin > Import, atau: mysql -u root -P 3307 < database.sql)

CREATE DATABASE IF NOT EXISTS koslink_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE koslink_db;

CREATE TABLE IF NOT EXISTS Mitra (
    id_mitra   INT AUTO_INCREMENT PRIMARY KEY,
    nama_mitra VARCHAR(100) NOT NULL,
    email      VARCHAR(100) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    no_hp      VARCHAR(20)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Properti_Kos (
    id_properti INT AUTO_INCREMENT PRIMARY KEY,
    id_mitra    INT NOT NULL,
    nama_kos    VARCHAR(100) NOT NULL,
    lokasi      TEXT NOT NULL,
    tipe_kos    ENUM('campur','putra','putri') NOT NULL DEFAULT 'campur',
    harga_mulai DECIMAL(10,2) NOT NULL DEFAULT 0,  -- diisi otomatis dari harga kamar termurah
    deskripsi   TEXT,
    FOREIGN KEY (id_mitra) REFERENCES Mitra(id_mitra) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Kamar (
    id_kamar    INT AUTO_INCREMENT PRIMARY KEY,
    id_properti INT NOT NULL,
    nama_kamar  VARCHAR(100) NOT NULL,
    harga_bulan DECIMAL(10,2) NOT NULL,
    sisa_kamar  INT NOT NULL DEFAULT 0,
    fasilitas   TEXT,
    FOREIGN KEY (id_properti) REFERENCES Properti_Kos(id_properti) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Pengguna (
    id_pengguna  INT AUTO_INCREMENT PRIMARY KEY,
    nama_lengkap VARCHAR(100) NOT NULL,
    email        VARCHAR(100) NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,
    no_hp        VARCHAR(20)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Transaksi (
    id_transaksi      VARCHAR(50) PRIMARY KEY,
    id_pengguna       INT NOT NULL,
    id_kamar          INT NOT NULL,
    id_mitra          INT NOT NULL,
    tanggal_masuk     DATE NOT NULL,
    durasi_bulan      INT NOT NULL,
    total_harga       DECIMAL(10,2) NOT NULL,
    status_pembayaran ENUM('pending','lunas','batal','expire') NOT NULL DEFAULT 'pending',
    link_pembayaran   VARCHAR(255),
    tanggal_transaksi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_pengguna) REFERENCES Pengguna(id_pengguna),
    FOREIGN KEY (id_kamar)    REFERENCES Kamar(id_kamar),
    FOREIGN KEY (id_mitra)    REFERENCES Mitra(id_mitra)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Tiket_Keluhan (
    id_tiket       INT AUTO_INCREMENT PRIMARY KEY,
    id_pengguna    INT NOT NULL,
    id_kamar       INT NOT NULL,
    judul          VARCHAR(100) NOT NULL,
    deskripsi      TEXT,
    status         ENUM('open','proses','selesai') NOT NULL DEFAULT 'open',
    tanggapan      TEXT NULL,
    tanggal_dibuat TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_pengguna) REFERENCES Pengguna(id_pengguna),
    FOREIGN KEY (id_kamar)    REFERENCES Kamar(id_kamar)
) ENGINE=InnoDB;

-- Data dummy. Password kedua akun = 12345 (sudah di-hash dengan password_hash / bcrypt)
INSERT INTO Mitra (nama_mitra, email, password, no_hp) VALUES
 ('Bli Wayan', 'mitra@koslink.com', '$2y$10$h8g2ClBkw4y2kfXcnaKEwOg4b1hRKd8NfO38CJ.jNV/EdCz84USQu', '08123456789');
INSERT INTO Pengguna (nama_lengkap, email, password, no_hp) VALUES
 ('Gede Wikananda', 'user@koslink.com', '$2y$10$h8g2ClBkw4y2kfXcnaKEwOg4b1hRKd8NfO38CJ.jNV/EdCz84USQu', '08987654321');
INSERT INTO Properti_Kos (id_mitra, nama_kos, lokasi, tipe_kos, harga_mulai, deskripsi) VALUES
 (1, 'Kos Adidya Pratama', 'Jimbaran, Bali', 'campur', 1500000, 'Kos nyaman dekat kampus Unud.');
INSERT INTO Kamar (id_properti, nama_kamar, harga_bulan, sisa_kamar, fasilitas) VALUES
 (1, 'Kamar Tipe A (AC + WiFi)', 1500000, 2, 'AC, WiFi, Kamar Mandi Dalam, Kasur, Lemari');
