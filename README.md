# Food Management System

A complete web application built with PHP and MySQL for managing food categories, ingredients, and dishes. The system supports two user roles: Admin and User.

## Features

### Admin Features
- **Dashboard**: Overview of system statistics (categories, ingredients, dishes, customers)
- **Category Management**: Create, edit, and delete categories
- **Ingredient Management**: Add ingredients under specific categories with units
- **Dish Management**: Create dishes by selecting ingredients from chosen categories
  - Manual quantity entry for each ingredient
  - Dynamic ingredient selection based on category
  - Full CRUD operations for dishes

### User Features
- **Registration & Login**: Secure user authentication
- **Dashboard**: Overview of available categories, ingredients, and dishes
- **Browse Categories**: View all categories with their ingredients and dishes
- **View Dishes**: See all dishes with detailed ingredient information and quantities

## Tech Stack

- **Frontend**: HTML, CSS, JavaScript, Bootstrap 5.3
- **Backend**: PHP (Core PHP)
- **Database**: MySQL
- **Security**: Prepared statements, password hashing, session management

## Database Structure

### Tables
1. **users**: User accounts (id, name, email, password, role)
2. **categories**: Food categories (id, name, description)
3. **ingredients**: Ingredients with category association (id, name, category_id, unit)
4. **dishes**: Created dishes (id, name, description, category_id)
5. **dish_ingredients**: Many-to-many relationship with quantities (id, dish_id, ingredient_id, quantity)

## Installation & Setup

### Prerequisites
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- PHP extensions: mysqli, session

### Step 1: Clone/Download the Project
```bash
# Download or clone the project to your web server directory
# For XAMPP: C:\xampp\htdocs\
# For WAMP: C:\wamp\www\
# For Linux: /var/www/html/
```

### Step 2: Database Configuration
1. Open `config/database.php` and update database credentials:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // Your MySQL username
define('DB_PASS', '');            // Your MySQL password
define('DB_NAME', 'food_management_system');
```

### Step 3: Create Database
1. Open phpMyAdmin or MySQL command line
2. Import the SQL file: `database/schema.sql`
   - Or manually run the SQL commands from the file

### Step 4: Web Server Configuration
1. Ensure your web server is running
2. Access the application via browser:
   - Local: `http://localhost/final php/index.php`
   - Or: `http://localhost/final-php/index.php` (if using spaces in folder name)

### Step 5: Default Admin Credentials
- **Email**: admin@example.com
- **Password**: admin123

**Important**: Change the admin password after first login for security!

## Project Structure

```
final php/
├── admin/              # Admin pages
│   ├── dashboard.php
│   ├── categories.php
│   ├── ingredients.php
│   └── dishes.php
├── api/                # API endpoints
│   └── get_ingredients.php
├── assets/             # Static assets
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── main.js
├── auth/               # Authentication pages
│   ├── login.php
│   ├── register.php
│   └── logout.php
├── config/             # Configuration files
│   ├── database.php
│   └── auth.php
├── database/           # Database schema
│   └── schema.sql
├── includes/           # Common includes
│   ├── header.php
│   └── footer.php
├── user/               # User pages
│   ├── dashboard.php
│   ├── categories.php
│   └── dishes.php
└── index.php          # Home page
```

## Usage Guide

### For Administrators

1. **Login** with admin credentials
2. **Create Categories**:
   - Go to Categories page
   - Fill in category name and description
   - Click "Add Category"

3. **Add Ingredients**:
   - Go to Ingredients page
   - Select a category
   - Enter ingredient name and unit (e.g., kg, grams, cups)
   - Click "Add Ingredient"

4. **Create Dishes**:
   - Go to Dishes page
   - Select a category
   - Enter dish name and description
   - Add ingredients from the selected category
   - Enter quantity for each ingredient
   - Click "Add Dish"

### For Users

1. **Register** a new account or **Login** with existing credentials
2. **Browse Categories** to see all available categories
3. **View Dishes** to see all dishes with their ingredients and quantities
4. Click on any dish to see detailed information

## Security Features

- ✅ Password hashing using `password_hash()` and `password_verify()`
- ✅ Prepared statements to prevent SQL injection
- ✅ Session-based authentication
- ✅ Role-based access control
- ✅ Input validation and sanitization
- ✅ CSRF protection (via session tokens)

## Browser Compatibility

- Chrome (latest)
- Firefox (latest)
- Edge (latest)
- Safari (latest)
- Opera (latest)

## Troubleshooting

### Database Connection Error
- Verify database credentials in `config/database.php`
- Ensure MySQL service is running
- Check database name matches in schema.sql

### Path Issues
- Ensure all file paths are correct
- Check web server configuration
- Verify file permissions

### Session Issues
- Ensure PHP session extension is enabled
- Check `session.save_path` in php.ini
- Clear browser cookies and try again

### API Endpoint Not Working
- Verify `api/get_ingredients.php` is accessible
- Check browser console for JavaScript errors
- Ensure AJAX requests are allowed

## Development Notes

- All database operations use prepared statements
- Passwords are hashed using PHP's `password_hash()` function
- Bootstrap 5.3 is loaded via CDN
- Bootstrap Icons are used for UI elements
- Responsive design works on mobile and desktop

## Future Enhancements

- [ ] Add image upload for dishes
- [ ] Implement search functionality
- [ ] Add pagination for large datasets
- [ ] Export data to CSV/PDF
- [ ] Add recipe instructions
- [ ] Implement user favorites/bookmarks
- [ ] Add shopping list generation
- [ ] Email notifications
- [ ] Multi-language support

## License

This project is open source and available for educational purposes.

## Support

For issues or questions, please check:
- PHP documentation: https://www.php.net/
- MySQL documentation: https://dev.mysql.com/doc/
- Bootstrap documentation: https://getbootstrap.com/

## Author

Created for Hassan Cook Chinese Food Specialist

---

**Note**: This is a complete, production-ready application with proper security measures, error handling, and user-friendly interface. All code is well-commented and follows PHP best practices.
