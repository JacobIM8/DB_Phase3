Shoe Store Database Tools

Requirements
- PHP 8.0 or later with the mysqli extension
- MySQL or MariaDB
- A browser

The repository does not include a database schema or sample-data SQL file. Get
the Shoe Store database setup script from the project/course materials before
following the database setup steps below. The app expects the database to be
named shoeStore and to contain the CUSTOMER, DEPARTMENT, EMPLOYEE, SALE_REC,
SHOE, and WISHLIST_ITEM tables with the project schema.

1. Download the repository

Clone the repository with Git, or download and extract its ZIP file. Open
Terminal (macOS/Linux) or a command prompt (Windows) and change into the
repository folder.

2. Install and start the local services

Install XAMPP, which includes PHP and MySQL/MariaDB. Start its MySQL service
from the XAMPP control panel.

3. Create and populate the database

Open http://localhost/phpmyadmin, create a database named shoeStore, select it,
and import the Shoe Store SQL setup script you obtained in step 1. The script
should create the expected tables and any starting records.

4. Configure the database connection

Open DB.php and set these values to match your local MySQL account:

    $host = "127.0.0.1";
    $user = "root";
    $pass = "";
    $dbname = "shoeStore";

For example, if your MySQL account has a password, replace the empty string in
$pass with that password. Do not commit real passwords to a public repository.

5. Start the PHP web server

Run the command for your system from the repository folder:

macOS with XAMPP:

    /Applications/XAMPP/xamppfiles/bin/php -S localhost:8000

Windows with XAMPP (adjust the folder if XAMPP is installed elsewhere):

    C:\xampp\php\php.exe -S localhost:8000

Linux or another setup with PHP on PATH:

    php -S localhost:8000

Keep the terminal window open while using the app. If port 8000 is already in
use, change 8000 in the command to another available port.

6. Open the app

Visit http://localhost:8000/index.php in your browser.

For a collaborator with the same database

The database is not included in the Git repository. Also, a database running on
your computer is not automatically shared with your collaborator. These steps
assume your collaborator already has a copy of the Shoe Store database on their
own MySQL/XAMPP installation, with the same schema and data.

1. Have them clone/download the repository and install/start PHP and MySQL as
   described above.
2. They should keep their own local database named shoeStore. If their database
   has a different name, they should use that name in DB.php.
3. They should edit DB.php to use the MySQL username and password on their own
   computer. Do not copy your local password or assume their credentials are
   the same.
4. They should start the PHP server from their downloaded repository and open
   http://localhost:8000/index.php on their computer.
5. To avoid clobbering changes, coordinate before using insert, update, or
   delete. Each collaborator's local database is separate unless both of you
   are explicitly configured to use a shared remote MySQL server.

If you intend for both computers to use one shared database, ask your database
administrator to provide the remote MySQL hostname, database name, username,
password, and network access instructions. Configure DB.php with those details
on each computer; do not expose database credentials in Git or a public
repository. Do not point a public-facing server at a database with unrestricted
write access.

Using the app

Choose a function from the menu. Search and insert/update/delete let you pick
database tables and columns. Updates and deletes use the selected match
condition; deletes require confirmation. These operations change database
records, so use a local/test database.

To use the manual query builder, first choose "Create or replace sales view".
Then choose "Build a query from SalesEmployeeView". The builder's SELECT box
supports multiple columns, and the FROM box fills automatically. WHERE,
GROUP BY, HAVING, DISTINCT, aggregate functions, and ORDER BY are available.

Troubleshooting

- "Connection refused" or "No such file or directory": make sure MySQL is
  running and DB.php has the correct host, username, and password. The host
  127.0.0.1 uses TCP instead of a local MySQL socket.
- "Unknown database shoeStore": create the database and import the project SQL
  setup script.
- "Table doesn't exist": the database has not been populated with the required
  project schema.
- "mysqli" is missing: enable/install PHP's mysqli extension or use the PHP
  executable bundled with XAMPP.
