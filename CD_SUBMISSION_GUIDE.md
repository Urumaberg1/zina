# 💿 Connectify - Project & CD Submission Guide
## Practical Exam: Web Programming (المشروع العملي لمادة برمجة الويب)

---

### 📋 Overview & Compliance with Exam Instructions
Based on the official exam guidelines:
1. **Idea Chosen:** Project #8 - **Social Media Web** (`Posts`, `Likes + Comments`, `Profile Page`).
2. **Technologies Used:** `HTML5`, `CSS3`, `JavaScript (ES6+)`, `Backend (PHP)` & `MySQL`.
3. **Mandatory Calculator Requirement:** *(مع تنفيذ اله حاسبه باستخدام الويب ثابته مع اي فكره من الافكار)* - Built as a persistent fixed floating calculator on all pages, plus a dedicated standalone calculator.
4. **Presentation Printed:** `PROJECT_PRESENTATION.html` is ready to open in the browser and print directly via `Ctrl+P`.
5. **CD Submission:** Copy the contents of this folder directly to the CD.

---

### 📂 CD Files & Directory Structure

```text
social media/
├── index.html                  # Main Social Feed, Stories, Messenger, Fixed Calculator
├── profile.html                # User Profile Page, Edit Info, Photo Gallery, Friends
├── calculator.html             # Standalone Dedicated Web Calculator
├── PROJECT_PRESENTATION.html   # Printable Presentation Document (A4 Print-Ready)
├── database.sql                # Complete Relational MySQL Database Script
├── CD_SUBMISSION_GUIDE.md      # This submission & discussion guide
├── css/
│   └── style.css               # External CSS stylesheet
├── js/
│   └── app.js                  # External JavaScript application logic
└── php/
    ├── config.php              # Database connection & CORS configuration
    ├── profile.php             # Profile API (GET & POST) with JSON/MySQL fallback
    ├── posts.php               # Posts CRUD API
    ├── likes.php               # Like & Multi-reaction API
    └── comments.php            # Comments API
```

---

### 🚀 How to Run the Project

#### Method 1: Instant Direct Browser Execution (Zero Setup)
1. Double-click **`index.html`** or **`profile.html`** in any web browser (Google Chrome, Microsoft Edge, Firefox).
2. The entire website runs immediately with full interactivity, real-time AI chats, persistent storage (`localStorage`), image uploads, and the fixed calculator.

#### Method 2: Running with XAMPP (Apache + PHP + MySQL)
1. Copy the `social media` folder into your XAMPP web root:
   `C:\xampp\htdocs\social media`
2. Start **Apache** and **MySQL** from the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin` in your browser.
4. Create a new database named `social_media_db`.
5. Import `database.sql` into `social_media_db`.
6. Open `http://localhost/social media/index.html` in your browser.

---

### 🖨️ How to Print the Project Presentation
1. Double-click **`PROJECT_PRESENTATION.html`** in your browser.
2. Click the blue **"🖨️ Print Presentation Document"** button at the top (or press `Ctrl + P`).
3. Set destination to your printer (or "Save as PDF") on standard **A4 paper**.
4. Write your team member names and IDs in the header table.

---

### 💡 Key Talking Points for the Practical Exam Discussion (أهم نقاط المناقشة)

1. **Profile Navigation:**
   - Clicking the user avatar or name in the navbar or sidebar navigates directly to the user's Profile page (`profile.html`).
   - Clicking author names on the feed differentiates between own posts (navigates to Profile) and friends' posts (opens AI chat).

2. **Web Calculator (الآلة الحاسبة):**
   - Built to satisfy the mandatory exam rule.
   - Present as a persistent floating tool on both `index.html` and `profile.html`, and accessible via navigation bars.
   - Features standard operations, scientific operations (sin, cos, tan, √, x²), calculation history tape, and a "Share to Feed / Chat" button.

3. **Likes & Multi-Reactions:**
   - Supports 6 emotional reactions (Like 👍, Love ❤️, Haha 😂, Wow 😮, Sad 😢, Angry 😡) with animated feedback and active counters.

4. **Comments System:**
   - Real-time comment submission and rendering on both the main feed and profile page.

5. **AI Conversational Engine:**
   - Autonomous simulated AI chat with realistic personalities and typing indicators for every community member.

6. **White / Dark Theme:**
   - Universal CSS variable switching with persistent theme memory.

