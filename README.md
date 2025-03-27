# PocketFrame Installer

PocketFrame Installer is a command-line tool designed to bootstrap a new PocketFrame application. It automates many steps of project setup by performing the following tasks:

## Installation

```bash
composer global require pocketframe/installer
```

## Usage

To create a new PocketFrame application, run:

```bash
pocketframe new <project_name>
```

- `<project_name>`: The name of your new project (required).
- `--config` (`-c`): Optional JSON configuration file to predefine setup options.

Follow the interactive prompts to provide database credentials and select optional features.

## Project Creation

1. **Project Creation:**
   - Clones a Git repository skeleton for a standardized project structure.
   - Creates a new directory based on the provided project name.

2. **Configuration Management:**
   - Loads optional JSON configuration if the `--config` option is provided.
   - Merges user-defined settings with defaults.

3. **System Requirement Checks:**
   - Verifies required PHP extensions (`pdo`, `mbstring`, `openssl`).
   - Optionally checks for Node.js installation for extended functionality.

4. **Dependency Installation:**
   - Installs PHP dependencies using Composer within the new project directory.

5. **Interactive Setup:**
   - Prompts the user for database configuration details.
   - Offers choices for database drivers (MySQL, PostgreSQL, SQLite) and collects credentials accordingly.

6. **Environment Configuration:**
   - Copies the `.env.example` file to `.env` and generates an application key.

7. **Additional Features:**
   - Configures Docker environment if needed.
   - Initializes a Git repository in the new project directory.
   - Executes post-install commands and sends telemetry data.

8. **Error Handling & Rollback:**
   - Catches exceptions during the installation process, displays error messages, and rolls back previously performed steps if necessary.


## System Requirements

- PHP with the following extensions: `pdo`, `mbstring`, `openssl`.
- Composer installed.
- Node.js (recommended for some features, though not required).

## Error Handling

If an error occurs during the installation, the installer will:

- Display a descriptive error message.
- Roll back any changes made up to that point (e.g., cloned repository, installed dependencies).

## Customization

You can customize the installation process by providing a custom configuration file with the `--config` option.

## License

This project is licensed under the MIT License.
