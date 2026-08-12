# TATA SALES — DESIGN SYSTEM & UI/UX SPECIFICATION

> **Document:** `design.md`
> **Product:** TATA Sales
> **Scope:** Public Website, Landing Page, Product/Service Experience, Lead Capture, Calculator, Sales Experience, CRM, Admin Dashboard, Responsive System, Accessibility, Motion, Design Tokens, Components, UX Rules
> **Design Direction:** Minimal, premium, calm, precise, conversion-focused
> **Reference Philosophy:** Apple-like product design principles — clarity, hierarchy, restraint, consistency, excellent typography, intentional motion, and frictionless interaction.
> **Important:** TATA Sales should be *inspired by the principles of high-end product design*, not imitate Apple's proprietary UI, assets, or branding.

---

# 1. DESIGN VISION

TATA Sales harus terasa seperti:

> **"Sistem yang kompleks, tetapi terasa sederhana."**

Produk ini memiliki banyak fungsi:

* katalog
* promo
* calculator
* lead capture
* CRM
* sales pipeline
* workflow
* WhatsApp
* analytics
* AI
* admin

Namun pengguna tidak boleh merasa sedang menggunakan software enterprise yang rumit.

Prinsip utama:

```text
COMPLEXITY INSIDE
SIMPLICITY OUTSIDE
```

Pengguna melihat sedikit hal yang penting.

Sistem menangani kompleksitas di belakang layar.

---

# 2. DESIGN GOALS

## 2.1 Clarity

Setiap halaman harus menjawab:

* Saya sedang di mana?
* Apa yang bisa saya lakukan?
* Apa yang paling penting?
* Apa langkah berikutnya?

## 2.2 Speed

Interface harus terasa cepat.

Tujuan:

* cepat dibuka
* cepat dipahami
* cepat melakukan aksi
* minim loading yang mengganggu

## 2.3 Focus

Jangan memberikan 10 CTA dengan bobot yang sama.

Setiap layar memiliki:

```text
1 PRIMARY ACTION
1–2 SECONDARY ACTIONS
```

## 2.4 Trust

Karena TATA Sales digunakan untuk penjualan dan data customer:

* informasi harus jelas
* status harus transparan
* harga tidak ambigu
* form tidak menipu
* tombol tidak menyesatkan

## 2.5 Conversion

Public website harus mendorong:

```text
Discover
→ Understand
→ Calculate
→ Trust
→ Enquire
→ Contact Sales
```

## 2.6 Scalability

UI harus mampu digunakan oleh:

* Automotive
* Property
* Education
* Wedding
* Furniture
* Healthcare
* B2B
* Services
* dan vertical lain

---

# 3. DESIGN PERSONALITY

TATA Sales harus terasa:

* premium
* modern
* calm
* reliable
* intelligent
* structured
* professional
* approachable

Hindari:

* terlalu banyak gradient
* glassmorphism berlebihan
* neon
* shadow berat
* border berlebihan
* warna terlalu ramai
* animasi berlebihan
* UI "dashboard template" generik
* icon overload
* card overload

---

# 4. DESIGN PRINCIPLE

## Principle 01 — Content First

Konten dan tindakan lebih penting daripada dekorasi.

## Principle 02 — One Visual Hierarchy

Setiap layar memiliki urutan:

```text
Context
↓
Headline
↓
Supporting information
↓
Primary action
↓
Secondary information
```

## Principle 03 — Progressive Disclosure

Jangan menampilkan semua informasi sekaligus.

Tampilkan:

```text
Essential
→ More
→ Advanced
```

## Principle 04 — Predictable Interaction

Jika tombol terlihat seperti tombol, harus dapat diklik.

Jika sesuatu tidak dapat diklik, jangan membuatnya terlihat interaktif.

## Principle 05 — Feedback Always

Setiap aksi harus memberikan feedback:

```text
Loading
Success
Error
Empty
Disabled
Processing
```

## Principle 06 — Minimize Cognitive Load

Satu layar tidak boleh memaksa user membaca terlalu banyak.

---

# 5. BRAND DESIGN DIRECTION

## Primary Character

TATA Sales bukan produk "tech yang berisik".

Karakter visual:

```text
White Space
+
Dark Neutral
+
One Accent Color
+
Strong Typography
+
Precise Layout
```

---

# 6. COLOR SYSTEM

Gunakan neutral-first system.

## Base

```text
--color-white: #FFFFFF
--color-black: #0A0A0A
--color-neutral-50: #F8F9FA
--color-neutral-100: #F1F3F5
--color-neutral-200: #E5E7EB
--color-neutral-300: #D1D5DB
--color-neutral-400: #9CA3AF
--color-neutral-500: #6B7280
--color-neutral-600: #4B5563
--color-neutral-700: #374151
--color-neutral-800: #1F2937
--color-neutral-900: #111827
```

## Brand Accent

Default:

```text
--color-primary: #1677FF
--color-primary-hover: #0F67E8
--color-primary-active: #0958CE
--color-primary-soft: #EAF3FF
```

Accent digunakan terutama untuk:

* primary CTA
* active state
* links
* progress
* important highlights

Jangan menjadikan seluruh UI berwarna biru.

## Semantic Colors

```text
Success:
#16A34A

Warning:
#D97706

Danger:
#DC2626

Info:
#2563EB
```

Semantic colors hanya digunakan ketika mempunyai makna.

---

# 7. DARK MODE

Dark mode optional untuk admin.

Public website default:

```text
Light
```

Admin dapat menyediakan:

```text
Light
Dark
System
```

Dark mode harus menggunakan true hierarchy, bukan sekadar membalik warna.

---

# 8. TYPOGRAPHY

Prioritas font:

```text
Inter
SF Pro Display / SF Pro Text jika legal tersedia
System UI
```

Fallback:

```css
font-family:
Inter,
-apple-system,
BlinkMacSystemFont,
"Segoe UI",
sans-serif;
```

---

# 9. TYPE SCALE

## Display

```text
Display XL: 64px / 1.05 / -0.04em
Display L: 56px / 1.08 / -0.035em
Display M: 48px / 1.1 / -0.03em
```

## Heading

```text
H1: 40px / 1.1 / -0.025em
H2: 32px / 1.15 / -0.02em
H3: 24px / 1.2 / -0.015em
H4: 20px / 1.25
```

## Body

```text
Body L: 18px / 1.6
Body M: 16px / 1.55
Body S: 14px / 1.5
Caption: 12px / 1.4
```

---

# 10. TYPOGRAPHY RULES

Hindari:

* terlalu banyak font weight
* ALL CAPS untuk paragraph
* heading terlalu panjang
* line length terlalu lebar

Ideal line length:

```text
45–75 characters
```

Hero heading:

```text
2–3 lines maximum
```

---

# 11. SPACING SYSTEM

Gunakan base unit 4.

```text
4
8
12
16
20
24
32
40
48
64
80
96
120
160
```

Recommended:

```text
Card padding:
24px

Section:
80–120px

Page horizontal:
24px mobile
32px tablet
48–80px desktop
```

---

# 12. GRID SYSTEM

Desktop:

```text
12 columns
```

Tablet:

```text
8 columns
```

Mobile:

```text
4 columns
```

Max content width:

```text
1200–1280px
```

Wide marketing sections:

```text
1440px max
```

---

# 13. RESPONSIVE BREAKPOINTS

```text
xs: < 480px
sm: 480px
md: 768px
lg: 1024px
xl: 1280px
2xl: 1440px
```

Design harus dimulai dari mobile.

---

# 14. MOBILE-FIRST RULE

Prioritas mobile:

1. headline
2. primary CTA
3. essential content
4. calculator
5. trust
6. supporting content

Desktop boleh menambahkan:

* comparison
* advanced filtering
* secondary navigation
* analytics
* multi-column layouts

---

# 15. RADIUS SYSTEM

Gunakan radius konsisten.

```text
XS: 6px
SM: 8px
MD: 12px
LG: 16px
XL: 20px
2XL: 24px
Pill: 999px
```

Marketing UI:

```text
16–24px
```

Admin:

```text
10–14px
```

Hindari radius berbeda-beda tanpa alasan.

---

# 16. SHADOW SYSTEM

Shadow harus subtle.

```text
Shadow XS:
0 1px 2px rgba(...)

Shadow SM:
0 4px 12px rgba(...)

Shadow MD:
0 8px 24px rgba(...)

Shadow LG:
0 20px 40px rgba(...)
```

Gunakan shadow hanya untuk:

* floating element
* dropdown
* modal
* elevated card

Jangan memberi shadow pada semua card.

---

# 17. BORDER SYSTEM

Default:

```text
1px solid #E5E7EB
```

Gunakan border ketika:

* memisahkan section
* input
* table
* card interactive

Bukan sebagai dekorasi.

---

# 18. ICON SYSTEM

Gunakan satu icon family secara konsisten.

Rekomendasi:

```text
Lucide
```

Guidelines:

```text
16px = inline
18px = button
20px = navigation
24px = feature
32px+ = empty state / illustration
```

Jangan menggunakan emoji sebagai icon utama produk.

---

# 19. BUTTON SYSTEM

## Primary

```text
Solid brand color
White text
```

Contoh:

```text
Ajukan Penawaran
Hubungi Sales
Mulai Sekarang
Simpan
```

## Secondary

```text
White / neutral background
Neutral border
```

## Tertiary

Text button.

## Destructive

Digunakan hanya untuk:

* delete
* cancel dangerous process
* revoke

---

# 20. BUTTON STATES

Setiap button wajib memiliki:

```text
default
hover
focus
pressed
disabled
loading
success
```

Loading:

```text
button content
→ spinner
→ disabled
```

Jangan mengubah ukuran button ketika loading.

---

# 21. INPUT SYSTEM

Input harus memiliki:

```text
Label
Input
Helper
Error
Optional indicator
```

Contoh:

```text
Nomor WhatsApp

[ 0812xxxxxxxx ]

Kami akan mengirim informasi melalui WhatsApp.
```

Error:

```text
Nomor WhatsApp belum valid.
```

---

# 22. FORM UX

Form harus:

* singkat
* jelas
* memiliki progress jika panjang
* menggunakan input sesuai data type
* autofill friendly
* mobile keyboard friendly

Jangan meminta data yang belum diperlukan.

---

# 23. LEAD FORM

Default lead form:

```text
Nama
Nomor WhatsApp
Produk/layanan diminati
Kebutuhan
```

Optional:

```text
Budget
Lokasi
Tanggal
Catatan
```

Gunakan progressive profiling.

---

# 24. TRUST UX

Sebelum meminta data:

jelaskan:

```text
Mengapa data dibutuhkan
Siapa yang menghubungi
Untuk apa digunakan
```

Contoh:

> Data Anda digunakan untuk menghubungkan Anda dengan tim sales terkait produk yang dipilih.

---

# 25. NAVIGATION PUBLIC WEBSITE

Desktop:

```text
Logo
Produk
Promo
Simulasi
Tentang
Artikel
[Hubungi Sales]
```

Mobile:

```text
Logo
Menu
CTA
```

Primary CTA dapat tetap visible jika relevan.

---

# 26. HEADER BEHAVIOR

Header:

```text
Transparent
→
Solid
```

saat scroll.

Transition:

```text
150–250ms
```

Jangan menggunakan animasi header yang berat.

---

# 27. HERO SECTION

Hero harus menjawab:

> Apa yang dijual?
> Kenapa relevan?
> Apa tindakan berikutnya?

Struktur:

```text
Eyebrow
Headline
Supporting Copy
Primary CTA
Secondary CTA
Product Visual
Trust indicator
```

Contoh automotive:

```text
Model Pilihan Bulan Ini

Suzuki Fronx

Temukan kendaraan yang sesuai kebutuhan Anda.
Lihat promo dan hitung simulasi sekarang.

[Hitung Simulasi]
[Lihat Detail]
```

---

# 28. HERO PRINCIPLE

Jangan membuat hero:

* terlalu banyak paragraph
* 4 CTA
* 10 badge
* animated particles
* background ramai

Hero harus terasa:

```text
Quiet
Confident
Focused
```

---

# 29. PRODUCT CATALOG

Desktop:

```text
Sidebar filter
+
Product grid
```

Mobile:

```text
Filter button
Sort button
Product list/grid
```

---

# 30. PRODUCT CARD

Card minimal:

```text
Image
Tag
Product name
Short descriptor
Price / starting price
Key spec
Primary action
```

Example:

```text
PROMO

Suzuki Fronx
Mulai dari Rp...
5-Seater · Automatic

[ Lihat Detail ]
```

Jangan memasukkan semua spesifikasi di card.

---

# 31. PRODUCT DETAIL PAGE

Structure:

```text
Breadcrumb
Product Hero
Gallery
Price
Promo
Primary CTA
Key specs
Variants
Features
Calculator
FAQ
Related products
Final CTA
```

Primary action selalu mudah ditemukan.

---

# 32. IMAGE GALLERY

Desktop:

```text
large primary image
thumbnail rail
```

Mobile:

```text
horizontal swipe gallery
```

Features:

* zoom
* swipe
* alt text
* optimized image
* lazy loading

---

# 33. PRODUCT INFORMATION HIERARCHY

Urutan:

```text
What
Why
How much
What's included
How to get it
```

Bukan:

```text
30 specifications
then price
then CTA
```

---

# 34. PRODUCT COMPARISON

Comparison digunakan untuk:

* automotive
* property
* electronics
* services

Mobile:

```text
Select products
↓
Comparison sections
↓
Highlight differences
```

Jangan membuat tabel yang overflow horizontal tanpa solusi.

---

# 35. PROMO EXPERIENCE

Promo section harus terasa seperti:

```text
Offer
+
Urgency
+
Proof
+
Action
```

Komponen:

```text
Promo title
Benefit
Valid until
Countdown
Voucher
CTA
Terms
```

---

# 36. COUNTDOWN

Countdown hanya digunakan jika deadline benar-benar nyata.

Format:

```text
03
Hari

12
Jam

44
Menit
```

Jangan menggunakan fake urgency.

---

# 37. VOUCHER UI

Voucher card:

```text
PROMO KHUSUS

Diskon Rp10.000.000

Kode:
TATA-SALES10

[Salin Kode]
```

Feedback:

```text
Copied ✓
```

---

# 38. CALCULATOR UX

Calculator adalah salah satu feature utama TATA Sales.

Prinsip:

```text
Simple inputs
Immediate feedback
Clear result
Strong CTA
```

Layout:

```text
Inputs
↓
Divider
↓
Result
↓
Explanation
↓
CTA
```

---

# 39. CALCULATOR INPUTS

Gunakan:

* currency input
* slider jika membantu
* select
* segmented control
* number input

Contoh:

```text
Harga
[ Rp 300.000.000 ]

DP
[ Rp 50.000.000 ]

Tenor
[ 60 bulan ▾ ]
```

---

# 40. CALCULATOR RESULT

Result harus paling menonjol.

```text
Estimasi cicilan

Rp 5.2 jt
per bulan
```

Tambahkan context:

```text
Estimasi berdasarkan data yang Anda masukkan.
```

CTA:

```text
[ Ajukan Penawaran ]
```

---

# 41. CALCULATOR UX RULE

Jangan membuat hasil:

* tersembunyi
* sulit dibaca
* muncul setelah submit tanpa alasan
* membutuhkan refresh

Jika memungkinkan:

```text
Input
→
Live result
```

---

# 42. LEAD CAPTURE AFTER CALCULATOR

User sudah mendapatkan value sebelum diminta contact.

Contoh:

```text
HASIL SIMULASI

Rp5.2 juta / bulan

Ingin mendapatkan penawaran yang lebih akurat?

Nama
WhatsApp

[ Kirim ke Sales ]
```

Ini jauh lebih baik daripada:

```text
Fill form
→
baru boleh lihat result
```

kecuali ada alasan bisnis kuat.

---

# 43. WHATSAPP CTA

CTA harus contextual.

Contoh:

```text
Chat dengan Sales
```

Pesan otomatis dapat mencakup:

```text
Product
Variant
Promo
Calculator result
```

---

# 44. FLOATING CTA

Mobile:

```text
[ WhatsApp Sales ]
```

dapat fixed di bottom.

Pastikan tidak menutupi:

* form button
* important content
* cookie banner

---

# 45. LIVE CHAT / AI

Chat widget:

```text
Bottom-right desktop
Bottom sheet mobile
```

Initial state:

```text
Ada yang bisa kami bantu?
```

Suggested prompts:

```text
Lihat promo
Tanya produk
Hitung simulasi
Hubungi sales
```

---

# 46. AI CHAT UI

AI response tidak boleh terlalu panjang.

Format:

```text
Jawaban
↓
Relevant action
```

Contoh:

> Suzuki Fronx tersedia dalam beberapa pilihan varian.

Actions:

```text
[Lihat Varian]
[Hitung Simulasi]
[Chat Sales]
```

---

# 47. AI DISCLOSURE

Jika menggunakan AI:

```text
Asisten AI
```

harus terlihat.

Jangan berpura-pura sebagai manusia.

---

# 48. SEARCH EXPERIENCE

Search harus:

* cepat
* tolerant terhadap typo
* suggest result
* show category
* show recent searches

Example:

```text
Cari produk...

fron
→ Fronx
→ Promo Fronx
→ Simulasi Fronx
```

---

# 49. FILTER UX

Desktop:

Persistent sidebar.

Mobile:

Bottom sheet.

Filter harus menampilkan jumlah active filter.

```text
Filter (3)
```

---

# 50. EMPTY STATES

Empty state bukan:

> No data.

Harus membantu.

Contoh:

```text
Belum ada prospek

Prospek baru akan muncul ketika pengunjung
mengisi form atau memulai percakapan.

[ Lihat Form ]
```

---

# 51. ERROR STATES

Error harus:

```text
What happened
Why
What to do next
```

Contoh:

> Simulasi belum dapat dihitung.

> Periksa kembali nilai DP dan tenor Anda.

[ Coba Lagi ]

---

# 52. SUCCESS STATES

Success harus memberi closure.

Contoh:

> Permintaan Anda berhasil dikirim.

> Tim sales akan menghubungi Anda melalui WhatsApp.

[ Kembali ke Produk ]

---

# 53. LOADING STATES

Gunakan:

* skeleton
* progress indicator
* spinner only untuk action pendek

Jangan menampilkan blank screen.

---

# 54. SKELETON RULE

Skeleton harus menyerupai bentuk content sebenarnya.

Jangan membuat loading:

```text
full-screen spinner
```

untuk halaman penuh jika skeleton memungkinkan.

---

# 55. PUBLIC FOOTER

Footer:

```text
Brand
Produk
Promo
Simulasi
Bantuan
Kontak
Privacy
Terms
Social
```

Jika bisnis memiliki address:

```text
Alamat
Jam operasional
```

---

# 56. MOBILE BOTTOM NAVIGATION

Hanya jika web app experience membutuhkan.

Untuk customer marketing website, jangan otomatis menggunakan bottom nav.

Untuk authenticated sales application:

```text
Home
Leads
Pipeline
Tasks
More
```

---

# 57. AUTHENTICATION UI

Login:

```text
Logo
Welcome
Email
Password
Remember
Login
Forgot password
```

Social login optional.

Register:

```text
Name
Business
Email
Password
```

Jangan membuat register terlalu panjang.

---

# 58. ONBOARDING

Setelah registrasi:

```text
Step 1
Nama bisnis

Step 2
Industry

Step 3
Add first product

Step 4
Sales team

Step 5
Connect contact channel

Step 6
Launch
```

Progress:

```text
2 / 5
```

---

# 59. ONBOARDING PRINCIPLE

User tidak perlu memahami semua fitur.

Goal onboarding:

> **mencapai first value secepat mungkin.**

Target:

```text
Create account
→
Add product
→
Publish
→
Get first lead
```

---

# 60. ADMIN INFORMATION ARCHITECTURE

Sidebar:

```text
Overview

Sales
  Leads
  Pipeline
  Tasks
  Customers

Catalog
  Products
  Categories
  Stock

Marketing
  Promotions
  Vouchers
  Campaigns
  Pages

Tools
  Calculators
  Forms
  Workflows

Communication
  Conversations
  WhatsApp
  Notifications

Analytics
  Overview
  Campaigns
  Sales Performance

Content
  Pages
  Articles
  Media

Settings
  Business
  Team
  Roles
  Integrations
  Billing
```

---

# 61. ADMIN SIDEBAR PRINCIPLE

Sidebar harus:

* compact
* predictable
* group-based
* collapsible

Desktop:

```text
240–260px
```

Collapsed:

```text
72px
```

Mobile:

```text
Drawer
```

---

# 62. ADMIN HEADER

Header:

```text
Breadcrumb
Page title
Search
Notifications
Help
User menu
```

---

# 63. ADMIN DASHBOARD

Dashboard bukan sekadar kumpulan card.

Hierarki:

```text
Summary
↓
Important trend
↓
Urgent actions
↓
Pipeline
↓
Performance
↓
Recent activity
```

---

# 64. DASHBOARD KPI

Default:

```text
Total Leads
Qualified Leads
Hot Leads
Deals Won
Revenue / Value
Conversion Rate
```

Setiap KPI:

```text
Current value
Comparison
Trend
Time range
```

---

# 65. KPI DESIGN

Contoh:

```text
Qualified Leads

320

↑ 12.4%

vs last period
```

Jangan menampilkan chart mini jika tidak memiliki informasi bermakna.

---

# 66. SALES PIPELINE UI

Kanban:

```text
New
Qualified
Proposal
Negotiation
Won
Lost
```

Card menampilkan:

```text
Name
Product
Value
Score
Sales
Last activity
```

---

# 67. KANBAN RULE

Jangan membuat card terlalu besar.

Card harus memudahkan scanning.

Primary:

```text
Customer
Value
Stage
```

Secondary:

```text
Product
Last activity
```

---

# 68. LEAD DETAIL PAGE

Layout desktop:

```text
Main content                 Right rail

Customer + Lead              Lead status
Conversation                 Score
Activities                   Assignment
Notes                        Next task
Timeline                     Actions
```

Mobile:

```text
Customer
Status
Primary action
Details
Timeline
```

---

# 69. LEAD SCORE UI

Gunakan:

```text
82
HOT
```

dengan semantic color.

Jangan membuat rainbow score.

---

# 70. CUSTOMER TIMELINE

Timeline:

```text
10:32
Viewed product

10:35
Completed calculator

10:37
Submitted lead

10:39
Assigned to Andi

10:42
Sales contacted
```

Ini membantu sales memahami context tanpa membaca seluruh database.

---

# 71. TASK SYSTEM

Task card:

```text
Follow-up Budi
Today · 14:00

Fronx GLX

[Complete]
```

Task statuses:

```text
Pending
In progress
Completed
Overdue
```

---

# 72. NOTIFICATION CENTER

Grouped:

```text
Today
Yesterday
Earlier
```

Types:

```text
New lead
Follow-up
Assignment
System
```

Unread indicator kecil dan tidak mengganggu.

---

# 73. PRODUCT ADMIN

Table columns:

```text
Product
Status
Price
Stock
Updated
Actions
```

Action menu:

```text
Edit
Duplicate
Preview
Publish
Archive
```

---

# 74. PRODUCT EDITOR

Gunakan tabs:

```text
General
Media
Pricing
Inventory
Attributes
SEO
Promotion
```

Simpan dengan autosave optional.

Primary action:

```text
Save Changes
```

Secondary:

```text
Preview
```

---

# 75. PROMOTION ADMIN

Structure:

```text
Campaign information
Offer
Eligibility
Schedule
Voucher
Display
Tracking
```

Preview:

```text
Website Preview
```

---

# 76. CALCULATOR ADMIN

Admin dapat membuat:

```text
Calculator
→ Inputs
→ Formula
→ Results
→ CTA
```

UI harus menggunakan builder sederhana.

Contoh:

```text
Input: Harga
Type: Currency

Input: DP
Type: Currency

Input: Tenor
Type: Select
```

---

# 77. FORM BUILDER

Fields:

```text
Text
Email
Phone
Number
Select
Radio
Checkbox
Date
Textarea
```

Setiap field:

```text
Label
Required
Placeholder
Help
Validation
```

---

# 78. WORKFLOW BUILDER

Visual builder:

```text
Trigger
↓
Condition
↓
Action
↓
Delay
↓
Condition
↓
Action
```

Node design harus sederhana.

Jangan membuat UI seperti developer IDE.

---

# 79. WORKFLOW NODE TYPES

```text
Trigger
Condition
Delay
Assign
Notify
Create task
Send message
Update lead
AI action
Webhook
End
```

---

# 80. WORKFLOW BUILDER UX

Sidebar:

```text
Nodes
```

Canvas:

```text
Workflow
```

Properties:

```text
Node settings
```

Save:

```text
Draft
Published
Paused
```

---

# 81. ANALYTICS UI

Analytics harus memiliki:

```text
Date range
Filters
Overview
Trend
Breakdown
Export
```

---

# 82. ANALYTICS VISUALIZATION

Gunakan:

* line chart
* bar chart
* funnel
* donut secukupnya
* table

Hindari:

* 3D chart
* pie chart berlebihan
* gradient rainbow
* decorative chart

---

# 83. FUNNEL UI

```text
Visitors
12,400

Product views
7,200

Lead
620

Qualified
280

Won
54
```

Gunakan funnel untuk:

* identify drop-off
* compare campaigns
* compare products

---

# 84. CAMPAIGN ANALYTICS

Campaign detail:

```text
Spend
Visitors
Leads
Qualified
Sales
Conversion
Estimated value
```

---

# 85. SALES PERFORMANCE

Sales leaderboard harus tidak terasa seperti kompetisi murahan.

Data:

```text
Leads handled
Response time
Qualified leads
Deals won
Conversion
```

Gunakan ranking hanya jika organisasi memang membutuhkannya.

---

# 86. SETTINGS IA

```text
Business
Brand
Domains
Users
Roles
Notifications
Integrations
AI
Billing
Security
Audit
```

---

# 87. ROLE & PERMISSION UI

Permission matrix:

```text
                View Edit Delete Manage

Owner             ✓    ✓     ✓      ✓
Manager           ✓    ✓     -      ✓
Sales             ✓    ✓     -      -
Content           ✓    ✓     ✓      -
```

Gunakan toggle yang jelas.

---

# 88. CONFIRMATION MODAL

Delete modal:

```text
Hapus Produk?

Produk ini akan dihapus dari daftar aktif.

[ Batal ] [ Hapus Produk ]
```

Untuk destructive action:

* jelaskan konsekuensi
* gunakan exact action label
* jangan gunakan "Yes/No"

---

# 89. DRAWER VS MODAL

Gunakan drawer untuk:

* edit quick
* detail
* filter
* secondary tasks

Gunakan modal untuk:

* confirmation
* focused action
* short form

Jangan menggunakan modal untuk halaman kompleks.

---

# 90. TOAST

Toast:

```text
Saved
Deleted
Published
Copied
```

Duration:

```text
3–5 sec
```

Error penting tidak boleh hanya toast.

---

# 91. TABLE UX

Table harus mendukung:

* search
* filter
* sort
* pagination
* row actions
* responsive behavior

Mobile:

Jangan memaksa table desktop.

Gunakan:

```text
stacked card
```

atau horizontal-scroll yang terkontrol.

---

# 92. SEARCH / COMMAND

Admin dapat menggunakan command/search:

```text
Search products...
Search leads...
Search customers...
```

Future:

```text
⌘K / Ctrl+K
```

---

# 93. EMPTY ADMIN DASHBOARD

Jika tenant baru:

```text
Selamat datang di TATA Sales.

Mulai dengan menambahkan produk pertama Anda.

[ Tambah Produk ]
```

Jangan menampilkan dashboard kosong tanpa guidance.

---

# 94. ACCESSIBILITY

Target minimum:

```text
WCAG 2.2 AA
```

Rules:

* keyboard accessible
* visible focus
* semantic HTML
* proper label
* alt image
* sufficient contrast
* no color-only indication
* reduced motion support
* accessible dialogs
* proper heading hierarchy

---

# 95. COLOR CONTRAST

Text normal harus memiliki contrast yang memadai.

Jangan menggunakan:

```text
light gray text on white
```

untuk informasi penting.

---

# 96. FOCUS STATE

Keyboard focus harus terlihat.

Gunakan:

```text
2px focus ring
```

dengan offset jika diperlukan.

---

# 97. TOUCH TARGET

Minimum target:

```text
44×44px
```

untuk touch controls.

---

# 98. MOTION SYSTEM

Motion harus:

```text
Purposeful
Fast
Subtle
```

Durations:

```text
Micro: 100–150ms
Normal: 180–250ms
Complex: 300–450ms
```

---

# 99. EASING

Default:

```text
ease-out
```

Untuk entering:

```text
ease-out
```

Untuk exiting:

```text
ease-in
```

Jangan membuat bounce animation default.

---

# 100. REDUCED MOTION

Jika:

```text
prefers-reduced-motion
```

aktif:

* kurangi transition
* hilangkan parallax
* hilangkan decorative animation

---

# 101. SCROLL ANIMATION

Gunakan hanya untuk:

* reveal section
* image transition
* KPI entrance

Jangan animasikan semua element.

---

# 102. MICROINTERACTION

Examples:

Button:

```text
hover
→ slight shift
```

Copy voucher:

```text
Copy
→
Copied ✓
```

Save:

```text
Saving...
→
Saved ✓
```

Lead:

```text
New
→
Assigned
```

---

# 103. IMAGE DIRECTION

Public marketing:

* real product
* authentic business context
* clean photography
* large crop
* high quality

Avoid:

* generic stock photo
* excessive overlays
* over-saturated colors

Admin:

Focus on data.

---

# 104. PRODUCT VISUAL HIERARCHY

Product image harus menjadi visual utama.

UI tidak boleh lebih mencolok daripada produk yang dijual.

---

# 105. BRAND CONSISTENCY

Semua vertical menggunakan engine visual yang sama.

Yang boleh berubah:

* imagery
* content
* industry vocabulary
* theme accent
* product fields

Yang tidak boleh berubah:

* spacing
* interaction patterns
* button behavior
* typography hierarchy
* accessibility
* grid logic
* form behavior

---

# 106. DESIGN TOKENS

Gunakan CSS variables atau token system.

```css
:root {
  --color-bg: #ffffff;
  --color-surface: #f8f9fa;
  --color-text: #111827;
  --color-muted: #6b7280;
  --color-border: #e5e7eb;

  --color-primary: #1677ff;
  --color-success: #16a34a;
  --color-warning: #d97706;
  --color-danger: #dc2626;

  --radius-sm: 8px;
  --radius-md: 12px;
  --radius-lg: 16px;
  --radius-xl: 20px;

  --space-1: 4px;
  --space-2: 8px;
  --space-3: 12px;
  --space-4: 16px;
  --space-5: 20px;
  --space-6: 24px;
  --space-8: 32px;
  --space-10: 40px;
  --space-12: 48px;
  --space-16: 64px;
  --space-20: 80px;
};
```

---

# 107. COMPONENT LIBRARY

Core components:

```text
Button
IconButton
Link
Badge
Tag
Avatar
Input
Textarea
Select
Checkbox
Radio
Switch
Slider
DatePicker
Search
Tabs
SegmentedControl
Card
Modal
Drawer
Popover
Tooltip
Dropdown
Toast
Alert
Breadcrumb
Pagination
Table
DataGrid
Accordion
Progress
Skeleton
EmptyState
ErrorState
```

---

# 108. TATA SALES COMPONENTS

Product:

```text
ProductCard
ProductGallery
ProductSpecs
ProductVariant
ProductCompare
ProductPrice
ProductStock
```

Sales:

```text
LeadCard
LeadScore
LeadTimeline
PipelineBoard
SalesCard
TaskCard
```

Marketing:

```text
PromoCard
Countdown
VoucherCard
CampaignBanner
```

Conversion:

```text
Calculator
LeadForm
CTASection
WhatsAppButton
ChatWidget
```

---

# 109. COMPONENT STATES

Setiap interactive component harus memiliki state matrix.

Contoh Input:

```text
default
hover
focus
filled
error
disabled
readonly
loading
```

Contoh Card:

```text
default
hover
selected
disabled
```

---

# 110. CONTENT DESIGN

Microcopy harus:

* singkat
* jelas
* manusiawi
* tidak bertele-tele

Hindari:

> "Silakan melakukan pengisian data dengan benar untuk melanjutkan ke tahapan berikutnya."

Gunakan:

> "Isi data berikut untuk melanjutkan."

---

# 111. ERROR COPY

Format:

```text
Problem
+
Action
```

Contoh:

> Nomor WhatsApp belum valid. Periksa kembali nomor Anda.

---

# 112. CTA COPY

Gunakan action-oriented copy.

Baik:

```text
Lihat Produk
Hitung Simulasi
Ajukan Penawaran
Hubungi Sales
Minta Quotation
Booking Sekarang
```

Hindari:

```text
Klik di sini
Submit
Next
Proceed
```

---

# 113. PUBLIC UX FLOW

Primary flow:

```text
LANDING
↓
DISCOVER
↓
PRODUCT
↓
PROMO
↓
CALCULATE
↓
LEAD
↓
WHATSAPP
↓
SALES
```

Jika user tidak ingin mengisi form:

```text
PRODUCT
↓
WHATSAPP
```

---

# 114. CUSTOMER FLOW — AUTOMOTIVE

```text
Homepage
↓
Pilih kendaraan
↓
Lihat detail
↓
Lihat promo
↓
Hitung kredit
↓
Lihat estimasi
↓
Ajukan penawaran
↓
Isi WhatsApp
↓
Sales
```

---

# 115. CUSTOMER FLOW — PROPERTY

```text
Homepage
↓
Project
↓
Unit
↓
Harga
↓
KPR Calculator
↓
Hasil
↓
Booking site visit
↓
Lead
↓
Sales
```

---

# 116. CUSTOMER FLOW — SERVICE

```text
Landing
↓
Paket
↓
Detail
↓
Estimator
↓
Recommendation
↓
Lead
↓
Consultation
```

---

# 117. ADMIN FLOW

```text
Login
↓
Onboarding
↓
Business setup
↓
Add product
↓
Create promotion
↓
Publish
↓
Receive lead
↓
Assign sales
↓
Track pipeline
↓
Analyze
```

---

# 118. SALES FLOW

```text
Login
↓
My leads
↓
Open priority lead
↓
Read context
↓
Contact customer
↓
Create follow-up
↓
Update stage
↓
Proposal
↓
Won / Lost
```

---

# 119. INFORMATION HIERARCHY RULE

Setiap page:

```text
Level 1:
What is this?

Level 2:
What matters?

Level 3:
What can I do?

Level 4:
What else can I know?
```

---

# 120. DENSITY RULE

Public:

```text
Low density
High visual hierarchy
```

Admin:

```text
Medium-high density
High information efficiency
```

Jangan membuat admin terlalu kosong.

Jangan membuat public website seperti admin dashboard.

---

# 121. ADMIN RESPONSIVE

Desktop adalah primary.

Tablet adapted.

Mobile:

* drawer navigation
* stacked KPI
* cards instead of tables
* sticky action
* simplified charts

---

# 122. MOBILE ADMIN NAVIGATION

Bottom navigation:

```text
Home
Leads
Pipeline
Tasks
More
```

Jika fitur terlalu banyak:

```text
More
→
Products
Promos
Analytics
Settings
```

---

# 123. ADMIN MOBILE ACTION BAR

Untuk lead:

```text
[Call]
[WhatsApp]
[Assign]
```

Tetap visible saat scrolling jika relevan.

---

# 124. PUBLIC MOBILE CTA BAR

Untuk product detail:

```text
[ Hitung Simulasi ] [ Chat Sales ]
```

fixed bottom.

---

# 125. PERFORMANCE TARGETS

Target design-performance:

```text
Fast initial render
Minimal JS
Image optimization
Lazy loading
Font optimization
```

Jangan menjadikan visual effect sebagai alasan performa buruk.

---

# 126. ACCESSIBILITY CHECKLIST

```text
- [ ] Semantic headings
- [ ] Keyboard navigation
- [ ] Focus visible
- [ ] Contrast compliant
- [ ] Image alt
- [ ] Form labels
- [ ] Error announced
- [ ] Modal focus trap
- [ ] Reduced motion
- [ ] Touch targets >= 44px
```

---

# 127. UX QUALITY CHECKLIST

```text
- [ ] User knows where they are
- [ ] Primary CTA obvious
- [ ] Navigation predictable
- [ ] Forms short
- [ ] Errors actionable
- [ ] Loading states clear
- [ ] Empty states useful
- [ ] Mobile usable
- [ ] Content scannable
- [ ] No unnecessary animation
```

---

# 128. PUBLIC WEBSITE QA

Sebelum launch:

```text
- [ ] Header desktop
- [ ] Header mobile
- [ ] Hero
- [ ] Product grid
- [ ] Product detail
- [ ] Promo
- [ ] Countdown
- [ ] Voucher
- [ ] Calculator
- [ ] Lead form
- [ ] WhatsApp
- [ ] Chat
- [ ] Footer
- [ ] SEO
- [ ] Accessibility
- [ ] Performance
```

---

# 129. ADMIN QA

```text
- [ ] Login
- [ ] Dashboard
- [ ] Product
- [ ] Product editor
- [ ] Promo
- [ ] Voucher
- [ ] Calculator
- [ ] Leads
- [ ] Pipeline
- [ ] Sales
- [ ] Tasks
- [ ] Analytics
- [ ] Users
- [ ] Roles
- [ ] Settings
- [ ] Audit
```

---

# 130. DESIGN REVIEW GATE

Setiap feature baru harus lolos:

## Visual

Apakah konsisten?

## UX

Apakah lebih mudah digunakan?

## Accessibility

Apakah keyboard/accessibility aman?

## Responsive

Apakah mobile baik?

## Performance

Apakah menambah beban besar?

## Conversion

Apakah membantu user mencapai tujuan?

---

# 131. "APPLE-LIKE" RULE

TATA Sales harus menerapkan prinsip:

```text
Less, but better.

Clarity before decoration.

Whitespace creates hierarchy.

Motion communicates state.

Typography creates structure.

Every detail has a reason.
```

Tetapi jangan:

* menyalin layout Apple
* menyalin icon Apple
* menyalin visual Apple
* menggunakan Apple branding
* membuat UI menjadi identik dengan produk Apple

---

# 132. PREMIUM VISUAL RULES

Gunakan:

```text
Large type
Large whitespace
Precise alignment
Minimal color
Real imagery
Strong product focus
Subtle depth
Subtle motion
```

---

# 133. WHAT MAKES TATA SALES LOOK PREMIUM

Bukan:

```text
gradient
shadow
glass
animation
```

melainkan:

```text
spacing
typography
alignment
consistency
content hierarchy
interaction quality
```

---

# 134. DESIGN SYSTEM FILE STRUCTURE

Recommended:

```text
design/
├── foundations/
│   ├── colors.md
│   ├── typography.md
│   ├── spacing.md
│   ├── motion.md
│   └── accessibility.md
│
├── components/
│   ├── buttons.md
│   ├── forms.md
│   ├── cards.md
│   ├── tables.md
│   ├── navigation.md
│   └── feedback.md
│
├── customer/
│   ├── homepage.md
│   ├── catalog.md
│   ├── product-detail.md
│   ├── promotion.md
│   ├── calculator.md
│   ├── lead.md
│   └── chat.md
│
├── admin/
│   ├── dashboard.md
│   ├── leads.md
│   ├── pipeline.md
│   ├── products.md
│   ├── promotions.md
│   ├── calculators.md
│   ├── workflows.md
│   ├── analytics.md
│   └── settings.md
│
└── design.md
```

---

# 135. DESIGN TOKEN IMPLEMENTATION

Recommended CSS architecture:

```text
tokens
↓
global styles
↓
components
↓
patterns
↓
pages
```

Jangan menanam warna:

```css
background: #1677ff;
```

di 30 file berbeda.

Gunakan:

```css
background: var(--color-primary);
```

---

# 136. COMPONENT NAMING

Use clear naming.

Good:

```text
PrimaryButton
LeadCard
ProductCard
PromoCard
CalculatorResult
LeadTimeline
```

Avoid:

```text
Box1
Card2
BlueCard
BigButton
```

---

# 137. PAGE NAMING

Public:

```text
HomePage
CatalogPage
ProductPage
PromotionPage
CalculatorPage
ContactPage
```

Admin:

```text
DashboardPage
LeadsPage
LeadDetailPage
ProductsPage
ProductEditorPage
PipelinePage
AnalyticsPage
SettingsPage
```

---

# 138. DESIGN-HANDOFF STANDARD

Setiap screen harus mendefinisikan:

```text
Page purpose
User goal
Primary CTA
Secondary CTA
Responsive behavior
Loading
Empty state
Error
Success
Accessibility
Analytics events
```

---

# 139. ANALYTICS EVENTS FROM UI

Contoh Product page:

```text
product_view
variant_select
promo_click
calculator_open
calculator_complete
lead_form_start
lead_form_submit
whatsapp_click
chat_open
```

Admin:

```text
lead_open
lead_assign
lead_stage_change
followup_create
quotation_create
product_publish
promo_publish
```

---

# 140. DESIGN QA WITH REAL DATA

Jangan hanya test dengan:

```text
Product A
Lorem ipsum
123
```

Gunakan:

* product name panjang
* harga besar
* nama customer panjang
* 20+ tags
* promo expired
* stock 0
* no image
* no description
* 1000 leads

UI harus tetap baik.

---

# 141. EDGE CASE UI

Harus mendukung:

```text
No products
No leads
No sales assigned
No campaign
No image
Very long title
Very long customer name
Thousands of leads
Slow connection
API failure
AI failure
WhatsApp unavailable
```

---

# 142. DESIGN PRINCIPLE FOR DATA-DENSE UI

Untuk admin:

```text
Density without clutter.
```

Gunakan:

* compact row
* whitespace antar section
* strong typography
* subtle separators
* sticky table headers
* consistent columns

---

# 143. PUBLIC VS ADMIN DESIGN DIFFERENCE

## Public

```text
Emotional
Visual
Simple
Conversion-oriented
```

## Admin

```text
Functional
Informational
Efficient
Action-oriented
```

Namun keduanya harus terasa berasal dari brand yang sama.

---

# 144. FINAL UI LAYERING

TATA Sales visual hierarchy:

```text
Brand
↓
Content
↓
Action
↓
Data
↓
System feedback
```

Tidak sebaliknya.

---

# 145. FINAL UX LOOP

Customer:

```text
SEE
↓
UNDERSTAND
↓
TRUST
↓
CALCULATE
↓
ACT
```

Sales:

```text
RECEIVE
↓
UNDERSTAND
↓
ACT
↓
FOLLOW-UP
↓
CLOSE
```

Admin:

```text
CONFIGURE
↓
MONITOR
↓
OPTIMIZE
↓
SCALE
```

---

# 146. TATA SALES DESIGN NORTH STAR

> **Make sophisticated sales technology feel effortless.**

Target akhir:

Pengunjung berkata:

> "Mudah dipahami."

Sales berkata:

> "Saya langsung tahu harus menghubungi siapa."

Manager berkata:

> "Saya tahu pipeline sedang seperti apa."

Owner berkata:

> "Saya tahu website saya menghasilkan apa."

Admin berkata:

> "Saya bisa mengatur semuanya sendiri."

---

# 147. FINAL DESIGN RULES

1. Jangan menambahkan UI hanya karena bisa.
2. Setiap component harus memiliki tujuan.
3. Setiap halaman harus memiliki primary action.
4. Gunakan whitespace sebagai alat hierarchy.
5. Gunakan typography sebagai alat navigasi.
6. Gunakan warna secukupnya.
7. Gunakan motion hanya untuk feedback dan orientation.
8. Mobile bukan versi kecil desktop.
9. Accessibility bukan fitur tambahan.
10. Performance adalah bagian dari UX.
11. Data harus mudah dipindai.
12. Error harus dapat dipulihkan.
13. AI harus transparan.
14. CTA harus contextual.
15. Jangan membuat user mengisi form yang belum perlu.
16. Gunakan progressive disclosure.
17. Jangan mengorbankan usability demi visual.
18. Semua vertical harus berbagi design language yang sama.
19. Admin harus kuat secara operasional.
20. Public website harus kuat secara conversion.

---

# 148. FINAL PRODUCT EXPERIENCE

TATA Sales harus terasa seperti satu produk utuh:

```text
                  TATA SALES
                       │
        ┌──────────────┼──────────────┐
        │              │              │
     CUSTOMER        SALES          ADMIN
        │              │              │
     Discover        Leads         Configure
        │              │              │
     Product        Pipeline       Products
        │              │              │
     Promo          Follow-up      Promotions
        │              │              │
   Calculator       Proposal       Calculator
        │              │              │
      Lead          Closing         Workflow
        │              │              │
        └──────────────┼──────────────┘
                       │
                    ANALYTICS
                       │
                    GROWTH
```

---

# 149. DESIGN SUCCESS CRITERIA

TATA Sales dianggap memiliki UI/UX berkualitas tinggi apabila:

```text
[ ] User baru memahami halaman tanpa tutorial panjang
[ ] CTA utama terlihat dalam 3 detik pertama
[ ] Mobile experience tetap nyaman
[ ] Form tidak terasa berat
[ ] Product information mudah dipindai
[ ] Calculator terasa mudah
[ ] WhatsApp handoff memiliki konteks
[ ] Sales dapat menemukan lead penting dengan cepat
[ ] Admin dapat melakukan pekerjaan tanpa bantuan developer
[ ] Error tidak membuat user buntu
[ ] Accessibility memenuhi target
[ ] Performance tidak dikorbankan demi visual
[ ] Semua halaman terasa satu keluarga
[ ] Design tetap bersih ketika data menjadi besar
```

---

# 150. FINAL DESIGN DIRECTION

**TATA SALES**

```text
Simple outside.
Powerful inside.
```

Visual:

```text
Clean
Quiet
Premium
Precise
Modern
Human
```

UX:

```text
Fast
Predictable
Contextual
Accessible
Conversion-focused
```

Architecture:

```text
Design System
→ Components
→ Patterns
→ Pages
→ Templates
→ Vertical Experience
```

Outcome:

> **Teknologi sales yang kompleks di belakang layar, tetapi terasa sederhana bagi manusia.**
