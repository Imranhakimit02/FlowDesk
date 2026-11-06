# FlowDesk

A PHP and MySQL/MariaDB ticket management application with configurable approval workflows, department routing, and staff assignment.

## Features

- Submit requests for existing users and track their tickets.
- Configure request categories and dynamic request forms.
- Define approval routes or allow requesters to select approvers.
- Review requests through the Head of Department (HOD) dashboard.
- Assign approved requests to staff and record completion or reassignment comments.
- Manage application records through the administrator dashboard.
- Attach files to requests.

## Project status

This repository currently contains the application source code. **A database schema and fictional demo seed data are not included yet. A fresh clone cannot run successfully without a compatible database.** The instructions below explain the setup once that database is available; they are not a verified fresh-install procedure yet.

This version is intended for local evaluation. Staff and HOD access uses an email selection and on-screen confirmation flow; it does not prove ownership through an emailed verification link. Administrator access uses a username and hashed password. Review authentication and authorization before exposing an instance publicly.

## Technology

- PHP with PDO and the PDO MySQL driver
- MySQL or MariaDB
- HTML, CSS, JavaScript, and Bootstrap 5.3.2
- Bootstrap and Google Fonts loaded from external services

No Composer or npm installation is required by the current source tree. Internet access is needed to load the external styles, scripts, and fonts.

## Run locally with XAMPP

### 1. Get the source

Start Apache and MySQL in XAMPP. Open a terminal in your XAMPP `htdocs` directory and run:

```sh
git clone https://github.com/Imranhakimit02/ticket_system.git flowdesk
cd flowdesk
```

Alternatively, download and extract the repository into `htdocs/flowdesk`.

### 2. Import the database (currently required separately)

Open your local phpMyAdmin, create a database named `flowdeskdb` using `utf8mb4`, and import a compatible SQL schema and fictional demo dataset supplied by the maintainer.

**No SQL import file is currently provided in this repository.** Creating an empty database alone is insufficient. The application references these tables:

```text
admin                 companies             departments
users                 approvers             request_types
workflow_steps        rt_approval_steps     approval_numbers
tickets               ticket_worksteps      ticket_approvals
ticket_logs
```

The demo dataset should include a company, departments, a requester, a HOD/approver, a staff member, an administrator with a PHP `password_hash()` password, and a request category with a working approval route. Demo account details should accompany that dataset. No demo credentials are currently supplied.

### 3. Configure the connection

Update the connection values in `config/db.php` to match your local database:

```php
$host = '127.0.0.1';
$dbname = 'flowdeskdb';
$username = 'root';
$password = '';
```

These are the current development defaults. Use your own local credentials and do not commit private database passwords.

### 4. Open the application

Visit these paths under `http://localhost/flowdesk/`:

| Page | Path |
| --- | --- |
| Request submission | `index.php` |
| Ticket tracking | `track_ticket.php` |
| Administrator login | `admin/admin_login.php` |
| HOD login | `hod/hod_login.php` |
| Staff login | `staff/staff_login.php` |

Ensure Apache can write to `uploads/`. Use fictional files when testing attachments.

### 5. Try the workflow

Once demo data is installed:

1. Search for the demo requester's email on the main page.
2. Choose a request category, complete its fields, and submit the request.
3. Complete the requester's on-screen confirmation.
4. Open the HOD login with the demo approver's email and approve the request through its configured steps.
5. Assign the approved ticket to a demo staff member.
6. Open the staff dashboard and complete the request with a comment.
7. Check the resulting status on the ticket tracking page.

## Troubleshooting

- **Database connection failed:** Check that MySQL is running and that `config/db.php` matches your database credentials.
- **Missing table or column:** Import a schema compatible with this source version. An empty database will not work.
- **User cannot be found:** The email must already exist in the `users` table. There is no public self-registration flow in the current source tree.
- **No request categories or approvers:** Configure the required records and workflow mappings through the administrator tools or demo dataset.
- **Attachment upload fails:** Check directory permissions and PHP's `file_uploads`, `upload_max_filesize`, and `post_max_size` settings.
- **Page is unstyled:** Check connectivity to the external Bootstrap and Google Fonts services.

## Publishing and portfolio

The repository's configured GitHub remote is [Imranhakimit02/ticket_system](https://github.com/Imranhakimit02/ticket_system). Repository visibility and remote contents have not been verified as part of this documentation update.

Before inviting others to install the project:

- Add a sanitized schema export, fictional demo seed data, and demo account instructions.
- Verify the entire setup on an empty database and record the PHP and database versions tested.
- Review committed files and Git history for private information. A PDF is currently tracked under `uploads/`; review it before making the repository public. Adding an ignore rule alone does not remove already tracked files or historical copies.
- Keep real user records, company data, attachments, credentials, and private database exports out of the public repository.
- Add screenshots using fictional data and choose a license if you want to grant others permission to reuse the code.

On your portfolio, add a FlowDesk project card with a screenshot, a short description, the technology stack, and a **View source** link to the GitHub repository. Visitors can follow this README for local setup once the database assets are included.

Suggested description:

> FlowDesk is a PHP and MySQL ticket management application with configurable approval workflows, department-based routing, staff assignment, and ticket tracking.

If you later provide a hosted instance, add a separate **Live demo** link. Running the complete application requires a PHP server and a database. Until a hosted demo is available, screenshots or a recorded walkthrough can demonstrate the workflow directly from your portfolio.
