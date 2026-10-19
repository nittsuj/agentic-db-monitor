# PRD — Agentic DB Monitor

Oct 19, 2026 · @valentino

## 1. Ringkasan produk

Agentic DB Monitor adalah aplikasi lokal single-user untuk memantau dan men-troubleshoot database MySQL/PostgreSQL lewat chat berbahasa alami, dengan AI yang hanya boleh menjalankan diagnosis read-only dan wajib meminta persetujuan untuk setiap perintah tulis.

PRD ini disusun dari kondisi kode di repo per 19 Oktober 2026 (commit terakhir `d2c7a97`, ditambah perubahan yang belum di-commit: Dockerfile, provider OpenAI-compatible). Status singkat:

- **Sudah jalan:** manajemen project database terenkripsi, chat AI dengan 5 tool diagnostik, antrean persetujuan per-write + audit log, dashboard metrik, CRUD threshold, pengaturan provider AI (Ollama, OpenAI, OpenAI-compatible).
- **Belum ada:** kolektor metrik dari database live, notifikasi desktop, pruning terjadwal, dan packaging NativePHP. Metrik di dashboard saat ini hanya berasal dari demo seeder.
- **Distribusi aktual:** Docker (`docker compose up`) di `localhost:8000`, bukan aplikasi desktop NativePHP seperti di rencana awal.

**Keputusan provider AI:** provider utama adalah LLM gateway ITS (`https://senopati.its.ac.id/llm-gateway/v1`, model `qwen3:14b`, format OpenAI Chat Completions). Ollama dan OpenAI tetap tersedia sebagai alternatif. Implementasinya belum dikerjakan; rinciannya ada di bagian 5.6.

**Dataset:** DBPA (SIGMOD 2023) sudah ditaruh di repo dan direncanakan untuk dua peran: sumber metrik realistis untuk demo dan benchmark berlabel untuk menguji ketepatan diagnosis agen (bagian 9).

## 2. Latar belakang & tujuan

**Masalah.** Mendiagnosis database yang lambat menuntut hafalan view telemetri yang berbeda per engine (`pg_stat_statements` vs `performance_schema`, `pg_stat_activity` vs `SHOW PROCESSLIST`). Asisten AI bisa membantu, tetapi memberi LLM akses SQL bebas ke database berisiko menimbulkan perubahan destruktif tanpa sengaja.

**Tujuan**

1. Pengguna bisa bertanya kondisi database dalam bahasa alami dan mendapat analisis yang didukung data nyata dari tool diagnostik.
2. Tidak ada SQL hasil karangan LLM yang dieksekusi tanpa persetujuan eksplisit; semua keputusan tercatat di audit log.
3. Setiap database (project) terisolasi: chat, metrik, threshold, dan tool hanya bekerja pada project aktif.
4. Pengguna mendapat peringatan saat metrik melewati threshold yang ia tentukan sendiri.
5. Penyimpanan lokal tetap ringan lewat retensi telemetri 48 jam.

**Bukan tujuan (non-goals)**

- Multi-user, login, atau multi-tenant; aplikasi berjalan untuk satu pengguna di mesin lokal.
- Engine selain MySQL dan PostgreSQL.
- Eksekusi otomatis perbaikan, termasuk mode "approve all" per sesi.
- Membuat extension atau mengubah konfigurasi server target (mis. mengaktifkan `pg_stat_statements`); aplikasi hanya mendeteksi dan memberi instruksi.

## 3. Pengguna & user story

**Persona utama:** developer atau DBA junior yang mengelola beberapa database MySQL/PostgreSQL (lokal, staging, atau server kampus), paham dasar SQL, tetapi tidak hafal view telemetri tiap engine. Ia ingin jawaban cepat tanpa takut AI mengubah data.

Sepuluh user story berikut diambil dari `docs/user_story.md`, dengan status implementasi saat ini.

| ID | User story (singkat) | Modul | Status |
| --- | --- | --- | --- |
| US-01 | Menyimpan kredensial database target dengan enkripsi | Target DB | Selesai |
| US-02 | Berpindah workspace project dari sidebar, data terisolasi | Target DB | Selesai |
| US-03 | Troubleshoot performa lewat chat bahasa alami | AI Diagnostik | Selesai |
| US-04 | AI mengambil skema tabel secukupnya | AI Diagnostik | Selesai |
| US-05 | AI otomatis menjalankan diagnosis read-only | AI Diagnostik | Selesai |
| US-06 | Setiap perintah tulis menunggu persetujuan eksplisit | Guardrail | Sebagian (agen tidak dilanjutkan setelah keputusan) |
| US-07 | Notifikasi desktop saat metrik melewati threshold | Guardrail | Sebagian (hanya tampilan breach di dashboard) |
| US-08 | Membersihkan / pruning telemetri lama | Guardrail | Sebagian (logika `Prunable` ada, belum dijadwalkan, belum ada tombol) |
| US-09 | Memilih provider dan model AI | Konfigurasi | Selesai |
| US-10 | Mengatur threshold per project | Konfigurasi | Selesai (UI); evaluasi live menunggu kolektor |

## 4. Ruang lingkup fitur

Enam halaman sudah tersedia; tiga kemampuan latar belakang (kolektor, notifikasi, pruning terjadwal) belum dibangun.

| Fitur | Halaman / komponen | Status | Catatan dari kode |
| --- | --- | --- | --- |
| Tambah project database | Modal `CreateProjectModal` | Selesai | Port & username terisi otomatis per driver; cek izin sebelum simpan |
| Pindah workspace | Sidebar + `GET /switch-project/{id}` | Selesai | Disimpan di session `active_project_id` |
| Edit / hapus project | — | Belum ada | Tidak ada UI; hanya bisa lewat DB |
| Chat diagnostik | `/chat` (`ChatWindow`) | Selesai | Streaming token, histori 20 pesan terakhir, maks 6 langkah tool |
| Usulan perubahan | Tool `propose_mutation` | Selesai | Ditolak bila statement tidak terklasifikasi sebagai tulis |
| Antrean persetujuan | `/approvals` (`ApprovalQueue`) | Selesai | Approve langsung eksekusi; deny hanya dicatat |
| Audit log | Tabel `action_logs` + dashboard | Selesai | Tetap ada walau project dihapus |
| Dashboard kesehatan | `/dashboard` (`HealthDashboard`) | Selesai | Rentang 1/6/24/48 jam, sparkline SVG, penanda breach |
| Threshold per project | `/thresholds` (`ThresholdManager`) | Selesai | Metrik terbatas: `connection_pool_used`, `slow_queries_per_min` |
| Pengaturan AI | `/settings/ai` (`AiSettingsForm`) | Selesai | Ollama, OpenAI, OpenAI-compatible; API key terenkripsi |
| Kolektor metrik | Job `CollectMetrics` | Belum ada | Dashboard bergantung pada data seeder |
| Notifikasi OS | NativePHP Notification | Belum ada | Bergantung pada NativePHP yang belum terpasang |
| Pruning telemetri | `model:prune` terjadwal + tombol manual | Belum ada | `MetricSample::prunable()` sudah ada dan dites |
| Packaging desktop | `native:build` | Belum ada | Diganti sementara oleh Docker |

## 5. Kebutuhan fungsional

Kode FR dipakai untuk pengujian dan pelacakan; "\[ada\]" berarti sudah diimplementasikan, "\[baru\]" berarti harus dibangun.

### 5.1 Manajemen project database

- **FR-01 \[ada\]** Pengguna mengisi nama, driver (MySQL/PostgreSQL), host, port, database, username, password. Port default 3306 / 5432.
- **FR-02 \[ada\]** Sebelum disimpan, `PermissionChecker` menguji koneksi dan akses ke view telemetri. Gagal = simpan diblokir dengan pesan yang bisa ditindaklanjuti; peringatan (mis. `pg_stat_statements` tidak ada) ditampilkan tanpa memblokir.
- **FR-03 \[ada\]** Password disimpan terenkripsi (cast `encrypted`). Kombinasi driver+host+port+database+username harus unik.
- **FR-04 \[ada\]** Project aktif disimpan di session; semua halaman membaca data hanya untuk project tersebut.
- **FR-05 \[baru\]** Edit dan hapus project dari UI, dengan konfirmasi; hapus project menghapus chat, metrik, threshold, dan pending action, tetapi audit log tetap.

### 5.2 Chat dan diagnosis AI

- **FR-06 \[ada\]** Pesan pengguna dan asisten disimpan per sesi chat per project; jawaban di-stream token demi token.
- **FR-07 \[ada\]** AI hanya punya 5 tool read-only: `list_tables`, `get_table_schema`, `get_slow_queries` (maks 50 baris), `check_connection_pool`, `analyze_table_bloat`. Tool berjalan otomatis tanpa konfirmasi.
- **FR-08 \[ada\]** System prompt mewajibkan discovery lewat `list_tables` dulu dan membatasi lookup skema 2–3 kali per permintaan.
- **FR-09 \[ada\]** Blok `<think>` dari model reasoning dibuang sebelum disimpan. Error provider/koneksi ditampilkan sebagai pesan ramah dan input pengguna dikembalikan.
- **FR-10 \[baru\]** Lebih dari satu sesi chat per project (buat, ganti nama, hapus sesi).

### 5.3 Guardrail: persetujuan per-write

- **FR-11 \[ada\]** AI hanya bisa mengusulkan perubahan lewat `propose_mutation`. `StatementClassifier` mengategorikan DDL, DML, atau maintenance; statement yang tidak terklasifikasi ditolak.
- **FR-12 \[ada\]** Usulan disimpan sebagai `PendingAction` berstatus `pending` dan tampil di `/approvals` dengan perintah persis.
- **FR-13 \[ada\]** Approve → eksekusi di database target → status `executed` atau `failed` + log. Deny → status `denied` + log, tanpa eksekusi.
- **FR-14 \[baru\]** Setelah approve/deny, hasil atau notifikasi penolakan dikirim kembali ke konteks chat agar agen bisa melanjutkan atau menyesuaikan rencana.
- **FR-15 \[baru\]** Antrean persetujuan difilter per project aktif dan menampilkan kategori, waktu usul, serta sesi chat asal.
- **FR-16 \[baru\]** Usulan berisi lebih dari satu statement ditolak; pending action yang lebih tua dari batas waktu tertentu diberi status `expired`.

### 5.4 Monitoring, threshold, dan alert

- **FR-17 \[ada\]** Dashboard menampilkan grafik per metrik untuk rentang 1/6/24/48 jam, penanda breach, pending approval, dan audit log terbaru.
- **FR-18 \[ada\]** Threshold per project: metrik, operator (gt, gte, lt, lte, eq, ne), nilai, aktif/nonaktif; CRUD penuh.
- **FR-19 \[baru\]** Job `CollectMetrics` setiap 5 menit untuk tiap project aktif, menulis `MetricSample` (`connection_pool_used`, `slow_queries_per_min`).
- **FR-20 \[baru\]** Evaluasi threshold setelah setiap koleksi; breach memicu notifikasi desktop (atau notifikasi in-app bila berjalan di Docker), dengan cooldown agar tidak spam.

### 5.5 Retensi

- **FR-21 \[ada\]** `MetricSample` memenuhi kontrak `Prunable` dengan batas 48 jam.
- **FR-22 \[baru\]** `model:prune` dijadwalkan (mis. tiap jam) dan tombol "Bersihkan telemetri" manual. Konfigurasi dan audit log tidak ikut terhapus.

### 5.6 Provider AI: gateway LLM ITS

Provider utama adalah LLM gateway ITS: endpoint yang kompatibel dengan OpenAI Chat Completions, memakai model `qwen3:14b`.

| Parameter | Nilai |
| --- | --- |
| Base URL | `https://senopati.its.ac.id/llm-gateway/v1` |
| Endpoint | `POST /chat/completions` |
| Autentikasi | Header `Authorization: Bearer <API key>` |
| Content-Type | `application/json` |
| Model default | `qwen3:14b` |
| Body minimum | `{"model": "qwen3:14b", "messages": [{"role": "user", "content": "..."}]}` |

- **FR-23 \[sebagian\]** Provider `openai_compatible` dengan URL dan model di atas sudah ada di kode yang belum di-commit. Prism memakai driver DeepSeek karena driver OpenAI memanggil `/responses`, bukan `/chat/completions`. Default aplikasi masih `ollama`.
- **FR-24 \[baru\]** Gateway ITS menjadi default: form `/settings/ai` dan fallback di `ChatWindow` terisi otomatis dengan base URL dan `qwen3:14b`. Ollama dan OpenAI tetap bisa dipilih.
- **FR-25 \[baru\]** API key gateway wajib diisi, disimpan terenkripsi di `ai_settings`, dan tidak pernah muncul di `.env`, log, README, atau repo.
- **FR-26 \[baru\]** Tombol "Tes koneksi" mengirim satu pesan pendek ke `/chat/completions` dan menampilkan hasil: berhasil, 401 (key salah), 429 (rate limit), atau gagal jaringan.
- **FR-27 \[baru\]** Agen membutuhkan `stream: true` dan `tools` (function calling). Keduanya diverifikasi di gateway sebelum implementasi; bila `tools` tidak didukung, chat turun ke mode tanpa tool dengan peringatan yang jelas.
- **FR-28 \[ada + baru\]** Blok `<think>` dari qwen3 sudah dibuang sebelum disimpan (FR-09); blok itu juga harus disembunyikan selama streaming.
- **FR-29 \[baru\]** Request ke gateway punya timeout eksplisit dan error 429/5xx diterjemahkan menjadi pesan yang bisa ditindaklanjuti; model divalidasi terhadap allow-list per provider (gateway ITS minimal `qwen3:14b`).

## 6. Kebutuhan non-fungsional

| Area | Kebutuhan | Kondisi sekarang |
| --- | --- | --- |
| Keamanan data | Password DB dan API key terenkripsi at rest dengan `APP_KEY`; key harus persisten | Ada; Docker menyimpan key di volume `app-data` |
| Keamanan eksekusi | Tidak ada SQL dari LLM yang menyentuh target tanpa approve; satu statement per usulan | Approve ada; multi-statement belum diblok |
| Akses jaringan | Aplikasi hanya bisa diakses dari mesin pengguna | Docker bind ke `0.0.0.0:8000` tanpa auth; perlu dibatasi ke `127.0.0.1` |
| Prinsip least privilege | Akun DB cukup punya hak baca telemetri; hak tulis opsional | Dicek saat simpan oleh `PermissionChecker` |
| Timeout | Koneksi target punya timeout eksplisit | 5 detik (cek izin), 10 detik (default) |
| Isolasi koneksi | Koneksi runtime dibuat per permintaan lalu di-purge | Ada (`TargetConnectionManager`) |
| Ketahanan job | Job gagal tidak menumpuk (`tries=1`, failed-jobs `null`) | Belum relevan; belum ada job |
| Kinerja chat | Token pertama < 5 detik pada provider normal; maks 6 langkah tool | Tidak diukur |
| Kualitas kode | Pint lolos, test hijau sebelum merge | 74 test case di `tests/` |
| Portabilitas | Jalan di Windows/macOS/Linux | Docker atau PHP 8.4 lokal |

## 7. Arsitektur & tech stack

Aplikasi adalah monolit Laravel 13 dengan UI Livewire; semua query ke database target lewat koneksi runtime yang dibuat per permintaan, dan jalur tulis hanya terbuka setelah approve.

&#91;embedded content: arsitektur saat ini · jalur baca dan tulis ke database target\]

Jalur baca berjalan otomatis dari agen ke driver; jalur tulis hanya lewat Guardrail. Komponen latar belakang (kolektor metrik, notifier, scheduler prune) belum ada sehingga tidak digambar.

| Lapisan | Teknologi |
| --- | --- |
| Bahasa & framework | PHP 8.4, Laravel 13.17 |
| UI | Livewire 3.7, Flux UI 2 (free), Tailwind CSS 4, Vite |
| LLM | Prism PHP 0.102; provider utama gateway LLM ITS (`qwen3:14b`) lewat driver DeepSeek untuk endpoint OpenAI-compatible; Ollama dan OpenAI sebagai alternatif |
| Penyimpanan lokal | SQLite |
| Database target | MySQL (`pdo_mysql`), PostgreSQL (`pdo_pgsql`) |
| Distribusi | Docker multi-stage (composer → node → php:8.4-cli), `php artisan serve` di port 8000, volume `app-data` |
| Pengujian | PHPUnit 12, `Prism::fake()`, Livewire test, Pint |

Struktur repo inti: `app/Livewire` (6 komponen halaman), `app/Services/Database` (koneksi, izin, klasifikasi, eksekusi, driver), `app/Models` (8 model), `app/Enums` (6 enum), `docs/` (HLD, ERD, diagram aktivitas dan sekuens).

## 8. Model data & antarmuka

Data aplikasi disimpan di SQLite lokal, delapan tabel dengan primary key UUID v7. Semua tabel anak ikut terhapus bersama project, kecuali `action_logs` yang dipertahankan.

| Tabel | Isi | Relasi ke project |
| --- | --- | --- |
| `projects` | Profil DB target; `password` terenkripsi, `options` json | — |
| `ai_settings` | Provider, model, base URL, `api_key` terenkripsi | Tidak ada (singleton) |
| `chat_sessions` | Sesi chat | Cascade |
| `chat_messages` | Role (user/assistant/tool/system), konten, meta | Cascade via sesi |
| `pending_actions` | Usulan perubahan: engine, kategori, perintah, status | Cascade |
| `action_logs` | Audit: keputusan, perintah, hasil/error, `project_name` denormalisasi | Null on delete |
| `metric_samples` | `key`, `value`, `captured_at`; prunable 48 jam | Cascade |
| `thresholds` | `key`, operator, nilai, aktif | Cascade |

Status pending action: `pending` → `approved` → `executed` / `failed`, atau `pending` → `denied`. Status `expired` sudah ada di enum tetapi belum dipakai.

**Rute web (Livewire full-page)**

| Rute | Komponen | Fungsi |
| --- | --- | --- |
| `GET /` | — | Redirect ke `/dashboard` |
| `GET /dashboard` | `HealthDashboard` | Metrik, breach, pending, audit |
| `GET /chat` | `ChatWindow` | Chat diagnostik |
| `GET /approvals` | `ApprovalQueue` | Approve / deny usulan |
| `GET /thresholds` | `ThresholdManager` | CRUD threshold |
| `GET /settings/ai` | `AiSettingsForm` | Provider AI |
| `GET /switch-project/{project}` | closure | Set project aktif |

Tidak ada REST API publik; seluruh interaksi melalui aksi Livewire. Kontrak internal ke database target ada di interface `DiagnosticDriver` (5 metode, implementasi MySQL dan PostgreSQL).

## 9. Dataset DBPA

DBPA dipakai sebagai data metrik realistis untuk demo dan sebagai benchmark berlabel untuk mengukur ketepatan diagnosis agen. Saat ini dataset baru diletakkan di folder `DBPA_dataset/` dan belum dipakai oleh kode.

| Atribut | Nilai |
| --- | --- |
| Sumber | [DBPA: A Benchmark for Transactional Database Performance Anomalies](https://github.com/hjhhsy120/DBPA_dataset), SIGMOD 2023 (Huang dkk.) |
| Lisensi | MIT; sitasi paper wajib dicantumkan |
| Isi setelah diekstrak | 112 file `.pickle`, 522 MB: 76 PostgreSQL (4 konfigurasi × 19 skenario) dan 36 MySQL (4 konfigurasi × 9 skenario tunggal) |
| Variasi hardware | 32/64 core × 128/256 GB RAM |
| Struktur per file | List kasus; tiap kasus = `[baris, jendela_anomali?, label]` |
| Baris | Satu sampel per 5 detik: timestamp + 110 nilai (PostgreSQL) atau 92 nilai (MySQL), urutan kolom sesuai `metrics_pg.csv` / `metrics_mysql.csv` |
| Jendela anomali | `[awal, akhir]` atau `[awal]` (sampai akhir kasus); tidak ada untuk Normal, Too Many Indexes, dan Small Shared Buffer (seluruh kasus anomali) |
| Panjang kasus | Normal 12 baris (1 menit); anomali umumnya 24–27 baris (2 menit), terpanjang 258 baris |
| Jumlah kasus (32 core/128 GB) | PostgreSQL: Normal 720, anomali tunggal 16–768 per skenario; MySQL: Normal 481, anomali 8–384 |
| Label | Kode singkat: `1` Concurrent Inserts, `2` Missing Indexes, `3` Heavy Workload, `4` Vacuum, `5` Concurrent Commits, `lockwait`, `manyindex`, `knob_*` (buffer kecil), `IO`, `normal`. File gabungan hanya menyimpan satu label, jadi label gabungan diambil dari nama file |
| Keamanan file | Pickle hanya berisi list angka, string, dan `datetime.datetime`; dibuka dengan unpickler terbatas yang menolak class lain |

**Skenario berlabel**

| Jenis | Skenario |
| --- | --- |
| Tunggal | Concurrent Inserts, Missing Indexes, Heavy Workloads, Vacuum, Concurrent Commits, Lock Waits, Too Many Indexes, Small Shared Buffer, I/O Saturation, Normal |
| Gabungan | Missing Indexes + Concurrent Commits, Missing Indexes + Lock Waits, Missing Indexes + Too Many Indexes, Heavy Workloads + I/O Saturation, Vacuum + Too Many Indexes, Too Many Indexes + Heavy Workloads, Too Many Indexes + I/O Saturation, Too Many Indexes + Lock Waits, Small Shared Buffer + Concurrent Inserts |

**Kebutuhan fungsional terkait dataset**

- **FR-30 \[baru\]** Importer DBPA: script offline (Python) mengekstrak pickle menjadi CSV per skenario; perintah Artisan `dbpa:import` memuat CSV itu ke `metric_samples` untuk project demo, dengan `meta.source = dbpa` dan label skenario. Aplikasi PHP tidak pernah membaca pickle langsung.
- **FR-31 \[baru\]** Mode replay: sampel DBPA diputar ulang dengan timestamp relatif ke waktu sekarang dan interval asli 5 detik; tiap kasus anomali disambung setelah segmen Normal agar ada pembanding, sehingga dashboard, threshold, dan alert bisa didemokan tanpa database yang benar-benar bermasalah. Ini menggantikan deret sinus di `DemoSeeder`.
- **FR-32 \[baru\]** Perluasan metrik: kunci metrik bertambah dari 2 menjadi set inti yang dipetakan dari kolom DBPA (tabel di bawah); threshold dan dashboard mengikuti.
- **FR-33 \[baru\]** Benchmark diagnosis: untuk tiap skenario, ringkasan metrik diberikan ke agen (lewat tool baru `get_metric_summary`), lalu jawabannya dibandingkan dengan label. Hasilnya disimpan sebagai laporan akurasi per skenario.
- **FR-34 \[baru\]** Rekomendasi threshold default per metrik, diturunkan dari perbandingan distribusi skenario Normal dan skenario anomali.

Pemetaan kolom DBPA ke kunci metrik dan tampilannya di dashboard dirinci di bagian 10.

## 10. Spesifikasi dashboard

Dashboard berkembang dari 2 sparkline menjadi 9 kartu KPI, 6 panel grafik, dan 1 panel anomali. Isinya sama untuk data live dan replay DBPA; yang berbeda hanya sumber data dan panel anomali.

&#91;embedded content: wireframe dashboard · header, 9 kartu KPI, 6 panel, panel anomali, 3 panel lama\]

Urutan dari atas ke bawah mengikuti alur kerja: lihat apa yang menyimpang, pahami penyebabnya, lalu tindak lanjuti lewat approval dan audit.

### 10.1 Header dan kontrol

- Nama project aktif, engine, dan badge sumber data: **Live** atau **Replay DBPA** (konfigurasi hardware + skenario).
- Rentang waktu ditambah **5m** dan **15m** di depan 1/6/24/48 jam. Data DBPA bersampel 5 detik dan satu kasus hanya 1–10 menit, sehingga rentang jam terlalu kasar.
- Pemilih skenario dan konfigurasi DBPA, tombol play/pause replay, dan toggle **mode blind** untuk benchmark.

### 10.2 Kartu KPI

Tiap kartu menampilkan nilai terakhir, sparkline sesuai rentang, dan warna status threshold.

| Kartu | Kunci metrik | Kolom PostgreSQL | Kolom MySQL | Satuan | Waspada bila |
| --- | --- | --- | --- | --- | --- |
| Koneksi % | `connection_pool_used` | `%Con` × 100 | `%Con` | % dari max\_connections | naik |
| Sesi aktif | `active_connections` | `Act` | `Act` | sesi | naik |
| Lock | `locks` | `Locks` | `Locks` | jumlah | naik |
| Query lama | `long_running_queries` | `LongQ` + `LongX` | tidak ada di MySQL | jumlah | naik |
| Lock wait | `lock_waiting_sessions` | `LWait` | tidak ada di MySQL | sesi | naik |
| CPU user | `cpu_user_pct` | `usr` | `usr` | % | naik |
| Load 1m | `load_1m` | `1m` | `1m` | load average | naik |
| Commit / rollback | `commits`, `rollback_ratio` | `comm`, `roll` | `comm`, `roll` | per sampel; % rollback | commit turun tajam |
| Buffer hit | `buffer_hit_ratio` | `ratio` × 100 | `ratio` | % | turun |

`%Con` dan `ratio` PostgreSQL disimpan sebagai pecahan 0–1, sedangkan MySQL sebagai persen; importer menormalkan keduanya ke persen. Kartu yang kolomnya tidak ada di engine aktif disembunyikan.

### 10.3 Panel grafik

| Panel | Seri | Paling berguna untuk skenario |
| --- | --- | --- |
| A. CPU & beban | `usr`, `sys`, `wai`, `1m`, `csw` | Too Many Indexes, I/O Saturation, Concurrent Inserts |
| B. Koneksi & sesi | `Conn`, `Act`, `%Con`; PG: `Idl`, `LIdl` | Heavy Workload, Concurrent Commits |
| C. Lock & tunggu | `Locks`; PG: `LWait`, `LongQ`, `LongX` | Lock Waits, Heavy Workload, Small Shared Buffer |
| D. Throughput transaksi | `comm`, `roll`, `insert`, `update`, `delete` | Lock Waits (commit jatuh ke \~0), Concurrent Inserts |
| E. I/O disk & buffer | disk `read`/`writ`, `ratio`; MySQL: `dirty`, `flushed`, `reads`; PG: `heapr`, `heaph`, `clean`, `back` | Concurrent Commits, Small Shared Buffer, Too Many Indexes (MySQL) |
| F. Snapshot konfigurasi | PG: `shared_buffers`, `work_mem`, `max_connections`, `maintenance_work_mem`, `max_wal_size`; MySQL: `Innodb_buffer_pool_size`, `max_connections` | Small Shared Buffer (nilai buffer kecil terlihat langsung) |

Semua grafik memakai sumbu waktu yang sama dan menampilkan garis threshold aktif. Panel F berupa tabel nilai terakhir, bukan grafik, karena setting jarang berubah.

### 10.4 Panel anomali (mode replay)

- Jendela anomali dari dataset (`[awal, akhir]`) digambar sebagai pita berwarna di semua grafik A–E.
- **5 sinyal terkuat:** metrik dengan rasio terbesar antara rata-rata jendela anomali dan baseline Normal pada konfigurasi yang sama.
- **Label skenario** tampil di mode biasa dan disembunyikan di mode blind; setelah agen menjawab, label dibuka dan hasil benar/salah dicatat (FR-33).
- Tombol **Tanya agen** membuka `/chat` dengan ringkasan jendela aktif sebagai konteks.

### 10.5 Sinyal tiap skenario dari data DBPA

Rasio rata-rata saat anomali dibanding rata-rata Normal, konfigurasi 32 core/128 GB, dihitung dari file pickle. Nilai dasar yang mendekati 0 menghasilkan rasio sangat besar, jadi angka ini menunjukkan arah dan urutan, bukan besaran absolut.

| Skenario | Sinyal terkuat PostgreSQL | Sinyal terkuat MySQL |
| --- | --- | --- |
| Lock Waits | `LongQ`/`LongX`/`LWait` \~860×, `Locks` 54×, commit \~0 | `Locks` 250×, `update` 22×, commit \~0 |
| Heavy Workload | `Locks` 13×, `usr` 41×, `Conn` 1,8× | `usr` 105×, `Locks` 47×, load 10×, `Conn` 1,8× |
| Concurrent Inserts | `LongX` 345×, `usr` 99×, `insert` 13×, load 13× | `usr` 21×, `insert` 3,2×, load 2,7× |
| Missing Indexes | `LongX` 82×, `usr` 69×, load 8×, commit 0,2× | `usr` 19×, `delete` 2,4×, `dirty` 1,8× |
| Vacuum | `LongX` 79×, `usr` 63×, load 8×, commit 0,1× | tidak ada di dataset MySQL |
| Concurrent Commits | `LWait` 6×, disk read 5,7×, `LongQ` 3,6×, `Conn` 1,9× | disk read 0 → 263, `usr` 3,7× |
| Too Many Indexes | `usr` 106×, load 15×, `csw` 7×, `LWait` 6× | `dirty` 2×, `delete` 2,5×, `update` 1,8× |
| Small Shared Buffer | `LongX` 10×, `LWait` 8×, throughput 0,1× | `dirty` 0,1×, throughput 0,3–0,7× |
| I/O Saturation | `usr` 123×, load 15×, `LWait` 21× | load 3,7×, `usr` 3,6× |

Tiga temuan yang memengaruhi desain: panel Lock & tunggu paling membedakan skenario di PostgreSQL; MySQL tidak punya `LongQ`/`LWait` sehingga lebih bergantung pada CPU, lock, dan throughput; dan `wai` (I/O wait) tidak naik pada I/O Saturation, jadi kartu I/O wait tidak dijadikan KPI utama.

### 10.6 Kebutuhan teknis dashboard

- **FR-35 \[baru\]** Kunci metrik bertambah menjadi 11 (tabel 10.2) dan `ThresholdManager` menerima semua kunci itu; threshold yang tidak relevan untuk engine aktif disembunyikan.
- **FR-36 \[baru\]** Agregasi otomatis: rentang ≤ 15 menit memakai sampel mentah 5 detik; rentang jam memakai rata-rata per menit (dan maksimum untuk lock) agar grafik tetap ringan.
- **FR-37 \[baru\]** Data replay disimpan dengan `meta.source = dbpa`, label, dan indeks jendela anomali; mengganti project atau skenario tidak mencampur data live dan replay.
- **NFR:** 11 kunci × sampel 5 detik × 48 jam ≈ 380 ribu baris per project. Mode replay hanya menyimpan skenario yang sedang diputar, dan pruning 48 jam tetap berlaku.

## 11. Metrik keberhasilan & kriteria penerimaan

**Metrik keberhasilan (untuk demo/penilaian)**

| Metrik | Target |
| --- | --- |
| Perintah tulis yang tereksekusi tanpa approve | 0 |
| Usulan yang tercatat di audit log (approve, deny, gagal) | 100% |
| Pertanyaan diagnostik umum yang dijawab dengan minimal 1 panggilan tool | ≥ 80% dari skenario uji |
| Akurasi diagnosis agen pada 9 skenario anomali tunggal DBPA (penyebab utama benar) | ≥ 70% (usulan awal, direvisi setelah baseline) |
| Skenario Normal DBPA yang salah dianggap anomali | ≤ 1 dari 4 konfigurasi hardware |
| Jeda antara breach threshold dan notifikasi | ≤ 5 menit (satu siklus kolektor) |
| Ukuran `metric_samples` setelah 7 hari berjalan | Hanya data ≤ 48 jam terakhir |
| Test suite | Hijau, Pint bersih |

**Kriteria penerimaan per fitur**

- [x] Profil dengan kredensial salah atau hak telemetri kurang ditolak dengan pesan jelas, tidak tersimpan.
- [x] Pindah project mengubah chat, metrik, dan threshold yang tampil.
- [x] Pertanyaan "query apa yang paling lambat?" memicu `get_slow_queries` dan jawaban di-stream.
- [x] `OPTIMIZE TABLE`, `CREATE INDEX`, `VACUUM` hanya berjalan setelah klik Approve.
- [x] Deny tidak mengeksekusi apa pun dan tercatat di audit log.
- [x] Audit log tetap terbaca setelah project dihapus.
- [ ] Setelah approve/deny, agen menerima hasilnya dan merespons di chat yang sama.
- [ ] Usulan `UPDATE ...; DROP TABLE ...` ditolak sebagai multi-statement.
- [ ] Metrik baru muncul di dashboard tiap 5 menit tanpa seeder.
- [ ] Breach memunculkan notifikasi sekali per periode cooldown.
- [ ] Sampel > 48 jam terhapus otomatis; tombol manual bekerja; konfigurasi utuh.
- [ ] Project bisa diedit dan dihapus dari UI.

## 12. Risiko, gap, dan langkah berikutnya

**Gap dan risiko yang ditemukan di kode**

| Temuan | Dampak | Usulan perbaikan |
| --- | --- | --- |
| `StatementClassifier` berbasis regex awal kalimat; `UPDATE a ...; DROP TABLE b` lolos sebagai DML dan dijalankan utuh oleh `statement()` | Tinggi | Tolak statement yang mengandung `;` di luar string; tampilkan kategori tiap statement |
| Docker membuka port 8000 di semua interface tanpa login | Tinggi | Bind ke `127.0.0.1:8000:8000` di `compose.yaml` |
| Dukungan `tools` dan streaming di gateway ITS untuk `qwen3:14b` belum terverifikasi; tanpa itu agen tidak bisa memanggil tool diagnostik | Tinggi | Uji dengan curl (`tools` + `stream: true`) sebelum implementasi; siapkan mode tanpa tool |
| API key gateway bocor lewat repo, log, atau contoh perintah | Tinggi | Simpan hanya terenkripsi di DB; jangan tulis key asli di dokumen atau commit |
| Membuka file `.pickle` sama dengan menjalankan kode dari file tersebut | Tinggi | Pickle DBPA terverifikasi hanya berisi list angka dan `datetime`; buka di script offline dengan unpickler yang hanya mengizinkan `datetime.datetime`; aplikasi hanya membaca CSV hasil konversi |
| `DBPA_dataset/` (121 MB, punya folder `.git` sendiri) belum masuk `.gitignore`; commit berikutnya akan menjadikannya embedded repo | Sedang | Tambahkan ke `.gitignore` atau jadikan git submodule; jangan masukkan ke image Docker |
| DBPA berisi deret waktu offline dari server 32–64 core; tool live (`get_slow_queries`, dll.) tidak bisa membaca data replay | Sedang | Benchmark memakai tool `get_metric_summary` khusus; hasilnya tidak diklaim setara diagnosis live |
| Satuan kolom DBPA berbeda antar engine (`%Con` dan `ratio` PostgreSQL berupa pecahan 0–1, MySQL berupa persen) dan MySQL tidak punya kolom long query / lock wait | Sedang | Normalisasi ke persen saat import; sembunyikan kartu yang kolomnya tidak ada di engine aktif |
| Akses gateway bisa terbatas pada jaringan/akun ITS dan punya rate limit | Sedang | Tes koneksi di pengaturan, pesan error 401/429 yang jelas, Ollama sebagai cadangan |
| `qwen3:14b` adalah model reasoning; blok `<think>` dan konsistensi tool calling bisa mengganggu | Sedang | Sembunyikan `<think>` saat stream; batasi langkah tool; uji skenario diagnostik |
| Agen tidak dilanjutkan setelah approve/deny (rencana awal: resume loop) | Sedang | Tambah pesan tool-result ke sesi chat saat keputusan dibuat |
| `/approvals` menampilkan pending dari semua project | Sedang | Filter per project aktif |
| NativePHP belum terpasang di `composer.json`, padahal HLD dan US-07 mengandalkannya | Sedang | Putuskan target distribusi (lihat pertanyaan terbuka) |
| Postgres hanya membaca skema `public` | Rendah | Parameter skema di `options` project |

**Pertanyaan terbuka**

- Target distribusi akhir: tetap Docker (web lokal) atau kembali ke NativePHP desktop? Ini menentukan bentuk notifikasi US-07.
- Berapa lama pending action dianggap kedaluwarsa?
- Apakah retensi 48 jam perlu bisa diatur pengguna?
- Metrik apa lagi yang perlu dikoleksi selain pool koneksi dan slow query?
- Apakah gateway ITS mendukung `tools` dan `stream` untuk `qwen3:14b`, dan berapa batas rate-nya?
- Apakah aplikasi akan dipakai dari luar jaringan ITS?
- Peran DBPA yang diinginkan: hanya data demo, hanya benchmark, atau keduanya? PRD ini mengasumsikan keduanya.
- Konfigurasi hardware dan engine DBPA mana yang dipakai untuk demo utama?

**Langkah berikutnya (urut prioritas)**

1. Perbaikan keamanan cepat: blok multi-statement, bind Docker ke localhost, filter approval per project, `DBPA_dataset/` ke `.gitignore`.
2. Validasi gateway ITS: uji `tools` dan streaming `qwen3:14b` lewat curl, lalu jadikan default beserta tes koneksi (FR-24 s.d. FR-29).
3. DBPA tahap 1: script konversi pickle ke CSV dengan normalisasi satuan, lalu `dbpa:import` beserta mode replay, 11 kunci metrik, dan dashboard baru sesuai bagian 10 (FR-30 s.d. FR-32, FR-35 s.d. FR-37).
4. Fase 4: `CollectMetrics` tiap 5 menit, evaluasi threshold, notifikasi dengan cooldown, scheduler di container.
5. DBPA tahap 2: tool `get_metric_summary`, benchmark diagnosis, dan rekomendasi threshold (FR-33, FR-34).
6. Fase 5: jadwal `model:prune` dan tombol bersihkan telemetri.
7. Resume agen setelah keputusan approval (FR-14) dan status `expired` (FR-16).
8. Edit/hapus project dan multi-sesi chat.
9. Fase 6: keputusan packaging (NativePHP build atau rilis image Docker), README akhir, demo.
