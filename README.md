<<<<<<<< Update Guide >>>>>>>>>>>

Immediate Older Version: 4.2.0
Current Version: 4.3.0

Feature Update:
1. Site Cookies System Added.
2. Fixed Sent Email To Users(User,Agent,Merchant).
3. Fixed Issues(Manual Payment Gateway Updated).
4. User Registration On/Off Restriction (User,Agent,Merchant).
5. Updated Balance Logs On All User's Transaction Section  (From Admin Panel).
6. Added Agent Information Page.
7. Added All Transaction Logs On Admin Panel.
8. Converted Money Out Section To Withdraw Section On Admin Panel.
9. Issues Fixed For Empty Select Option.



Please Use This Commands On Your Terminal To Update Full System
1. To Run project Please Run This Command On Your Terminal
    composer update && composer dumpautoload && php artisan migrate:fresh --seed && php artisan passport::install --force
