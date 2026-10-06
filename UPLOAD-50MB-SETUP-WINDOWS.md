# 50 MB Upload Configuration

The application enforces a maximum source-file size of **50 MB**. PHP/Apache must also permit requests of at least 50 MB.

## XAMPP on Windows

Open the PHP configuration used by Apache (XAMPP Control Panel → Apache → Config → PHP (php.ini)) and set:

```ini
upload_max_filesize = 50M
post_max_size = 60M
max_execution_time = 300
max_input_time = 300
max_file_uploads = 50
```

Restart Apache after saving `php.ini`.

The application itself rejects anything above 50 MB, even if PHP is configured higher.

## Question count

There is **no fixed 30-question upload limit**. A faculty member can upload a large question pool (for example 60, 100, 200 or more questions) within the 50 MB file limit. The COE blueprint is responsible for selecting the final questions used in a generated paper.
