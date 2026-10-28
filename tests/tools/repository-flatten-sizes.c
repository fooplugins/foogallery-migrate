#include <stdio.h>
#include "php.h"
#include "Zend/zend_alloc_sizes.h"
int main(void) {
 printf("{\"zval\":%zu,\"bucket\":%zu,\"array\":%zu,\"string_header\":%zu,\"execute_data\":%zu,\"mixed8\":%zu,\"mixed32\":%zu,\"chunk\":%zu,\"page\":%d}\n", sizeof(zval),sizeof(Bucket),sizeof(zend_array), XtOffsetOf(zend_string,val), sizeof(zend_execute_data), HT_SIZE_EX(8,HT_SIZE_TO_MASK(8)),HT_SIZE_EX(32,HT_SIZE_TO_MASK(32)),ZEND_MM_CHUNK_SIZE,ZEND_MM_PAGE_SIZE);
}
