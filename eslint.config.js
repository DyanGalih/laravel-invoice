const vue = require('eslint-plugin-vue')
const prettier = require('eslint-config-prettier')

module.exports = [
  ...vue.configs['flat/recommended'],
  {
    files: ['**/*.js', '**/*.vue'],
    rules: {
      // Existing screens use these patterns; keep the upgrade lint-compatible without a broad rewrite.
      'vue/multi-word-component-names': 'warn',
      'vue/no-deprecated-slot-attribute': 'warn',
      'vue/no-mutating-props': 'warn',
      'vue/no-ref-as-operand': 'warn',
      'vue/no-unused-vars': 'warn',
      'vue/prefer-import-from-vue': 'warn',
      'vue/require-valid-default-prop': 'warn',
      'vue/return-in-computed-property': 'warn',
    },
  },
  prettier,
]
