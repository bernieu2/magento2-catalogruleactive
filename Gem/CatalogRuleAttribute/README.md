- Example module to create a new product attribute 'catalog_rule_active'
- Checks the catalogrule_product table once daily (12:01am)
- Updates the new product attribute to yes if catalog rule is active
- When creating a sales rule, you can now use a NOT condition to exclude any products that have an active catalog rule from being processed under a sales rule as well (see screenshot - salesrulesetupexample.png)

Made in response to StackExchange question -
https://magento.stackexchange.com/questions/319161/how-do-i-exclude-products-with-discount-from-coupon-codes/331821
