import js from '@eslint/js'
import globals from 'globals'
export default [
  { ignores: ['node_modules/**'] },
  js.configs.recommended,
  { files: ['**/*.{js,jsx}'], languageOptions: { globals: { ...globals.browser, ...globals.node }, parserOptions: { ecmaFeatures: { jsx: true } } }, rules: { 'no-unused-vars': ['error', { varsIgnorePattern: '^[A-Z_]', argsIgnorePattern: '^_' }] } },
]
