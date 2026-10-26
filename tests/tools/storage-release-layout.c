/* Layout facts only; not an allocator certificate or customer prerequisite. */
#include <stdio.h>
#include "php.h"
int main(void) {
 printf("{\"php\":\"%s\",\"pointer\":%lu,\"zval\":%lu,\"hashtable\":%lu,\"bucket\":%lu,\"bucket_pointer\":%lu}\n", PHP_VERSION,
  (unsigned long)sizeof(void*), (unsigned long)sizeof(zval), (unsigned long)sizeof(HashTable),
  (unsigned long)sizeof(Bucket), (unsigned long)sizeof(Bucket*));
 return 0;
}
