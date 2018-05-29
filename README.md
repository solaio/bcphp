# bcphp
Simple Blockchain with PHP

PHP によるシンプルなブロックチェーン

## Operating Environment
- Web Server (Apache, nginx, etc)
- PHP 5.6.0+
- MySQL 5.6+
- Composer

## Installation
1. Edit the index.php code on line 17,18 and 26 - 28 according to your environment.

```
define('NODE_URL', 'http://localhost/bcphp/');
define('NEIGHBOUR_NODE_URL', '');
```

```
ORM::configure('mysql:host=localhost;dbname=bcphp');
ORM::configure('username', 'root');
ORM::configure('password', 'root');
```
2. Place files in public directory.
3. Perform initialization with Composer. 

```
composer init
```

## Contributing
Contributions are welcome! Please feel free to submit a Pull Request.

## Reference
- https://qiita.com/hidehiro98/items/841ece65d896aeaa8a2a
- http://co.bsnws.net/article/107
- https://qiita.com/iritec/items/5342a8b6031c982c85c4
