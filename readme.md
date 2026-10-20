⚡ FlowDesk - Enterprise Ticketing & Workflow Engine

FlowDesk is a highly adaptive, multi-tier enterprise ticket tracking and approval workflow engine built with PHP (PDO) and Bootstrap 5. Designed for streamlined corporate governance, it enables employees to seamlessly request permissions, routes dynamic validation objects across departments, and structures localized ticket isolation tiers for HODs and operations staff.

🚀 Key System Capabilities


🏢 User Portal (/)

• Account Lookup: Direct authorization barrier requiring users to search strictly by email to protect database lookups.
• Dynamic Form Schemas: Fulfills custom asynchronous form parsing driven by an automated API JSON schema engine (api/get_request_schema.php).
• Custom Routing Construction: Allows users requesting flexible approvals to select specific eligible approvers manually across multi-tiered workflows (up to an admin-defined limit).
• Robust File Handling: Implements secure base64-token hashing algorithms to handle collateral attachment storage safely (.pdf, .doc, .docx, .jpg, .png, .zip).

👔 Head of Department (HOD) Portal (/hod/)

• Dual Action Queues: Consolidates multi-stage ticket workflow approvals and operations team assignments into a single context-aware timeline matrix.
• Reassignment Arbitrage: Intercepts workload exception flags raised by operational staff. HODs can either authorize a ticket re-route back into the pool or reject it to return it to the original handler.
• Strict Assignment Scoping: Protects resource allocation by enforcing rules that prevent HODs from routing tasks to personnel outside their designated department boundaries.

🛠️ Operational Staff Dashboard (/staff/)

• Task Matrix Isolation: Filters active ticket views exclusively to items directly assigned to the logged-in staff member or historical records under their purview.
• Status Lifecycles: Allows team members to transition active queues into Completed states or dispatch a Request for Reassignment flag up the corporate tier.
• Mandatory Action Commentaries: Enforces system-level input policies that require clarifying explanations before processing any lifecycle status updates.

🛡️ Administration Suite (/admin/)

• Command Center Dashboard: High-contrast layout offering centralized metrics for users, departments, companies, request categories, and active tickets.
• Integrated Slicers: Deep-dive audit control using PowerBI-style preset date filters (Today, Last 7 Days, Last 30 Days) to query logs instantly.
• Low-Code Form Builder: Visual engine empowering administrators to instantly design question categories, assign target engineering teams, and map out background data parameters.

📊 Expanded Data Architecture

FlowDesk relies on closely relational entity mappings to maintain strict integrity across ticketing workflows:

Entity / System Table	Primary Function
companies	The global legal enterprise parent node.
departments	Strategic branches mapped explicitly under designated parent companies.
users	The main workforce registry tracking authorization levels (staff, hod, admin).
approvers	Validated HOD users authorized to sign off on specific departmental queues.
request_types	Directory tracking unique form categories, target destinations, and raw JSON form schemas.
workflow_steps	Sequential routing configurations tracking target action layers (e.g., L1, L2, L3).
tickets	The centralized operational queue housing transaction hashes, JSON metadata blobs, and audit markers.
ticket_approvals	Active audit trails keeping granular tabs on individual step decisions and supersedence logs.
ticket_logs	Comprehensive transaction registry archiving actions taken by operational staff and HOD systems.


🛠️ Technology Stack

• Core Language: PHP 8.x (utilizing strict object-oriented paradigms and type assertions)
• Database Interface: PHP Data Objects (PDO) backed by native transaction rollbacks to safeguard data integrity
• UI/UX Framework: Bootstrap 5 (styled using a high-contrast Plus Jakarta Sans layout)
• Dynamic Interactions: Native JavaScript Fetch API for asynchronous JSON parsing

🔒 Security Architectures

• Anti-CSRF Integration: Cryptographically secure validation tokens injected dynamically into sensitive administration operations.
• Sanitization Layer: Complete processing protection utilizing robust validation structures (FILTER_VALIDATE_INT, FILTER_VALIDATE_EMAIL) to prevent injection vulnerabilities.
• Transaction Safety: Employs explicit row-level semantic locking structures (FOR UPDATE) to completely eliminate race conditions during concurrent cross-department assignment updates.

💻 Quick Setup Instructions

1. Clone the Repository:bash
git clone https://github.com
cd flowdesk
Use code with caution.
2. Database Provisioning:
	• Open your local MySQL instance.
	• Import the database schema from your initialization file (e.g., database.sql).
	• Configure your database credentials securely inside your central file wrapper located at: /config/db.php.
3. Verify Directory Permissions:
Ensure your local server environment grants write permissions to the file upload repository:bash
chmod 755 uploads/
Use code with caution.
4. Launch Portal:
Point your local Apache virtual host or Docker web development dashboard container directly to index.php.