Shoe Store Database Tools

Run the app
1. Start MySQL in XAMPP.
2. From Terminal, run:
   cd /Users/jacobmelgar/DB_Phase3
   /Applications/XAMPP/xamppfiles/bin/php -S localhost:8000
3. Open http://localhost:8000/index.php.

The function menu provides table search, generic row insert/update/delete,
sales-view creation, and a manual query builder. For data changes, choose any
table from the database, then enter the row values or match condition. Updates
and deletes require a matching column and value; delete also requires
confirmation.

Create or replace SalesEmployeeView before using the query builder. The builder
uses the view's columns in its SELECT multi-select, fills FROM automatically,
and supports WHERE, GROUP BY, HAVING, DISTINCT, aggregate functions, and ORDER
BY.
