import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const require = createRequire(import.meta.url)

async function read(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8')
}

async function sourceFiles(directory) {
  const entries = await readdir(path.join(root, directory), {
    withFileTypes: true,
  })
  const files = []

  for (const entry of entries) {
    const relativePath = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      files.push(...(await sourceFiles(relativePath)))
    } else if (entry.name.endsWith('.js') || entry.name.endsWith('.vue')) {
      files.push(relativePath)
    }
  }

  return files
}

const scripts = await sourceFiles('resources/scripts')
const source = (await Promise.all(scripts.map(read))).join('\n')

const tailwind = require(path.join(root, 'tailwind.config.js'))
const themes = await read('resources/sass/themes.scss')
const variables = Object.fromEntries(
  [...themes.matchAll(/--color-primary-(\d+):\s*([^;]+);/g)].map(
    ([, shade, value]) => [`--color-primary-${shade}`, value.trim()],
  ),
)

for (const [shade, color] of Object.entries(
  tailwind.theme.extend.colors.primary,
)) {
  assert.match(
    color,
    /^rgba\(var\(--color-primary-\d+\), <alpha-value>\)$/,
    `primary-${shade} must use comma-compatible RGBA syntax`,
  )
  const expanded = color
    .replace(
      /var\((--color-primary-\d+)\)/,
      (_, variable) => variables[variable],
    )
    .replace('<alpha-value>', '1')
  assert.match(expanded, /^rgba\(\d+, \d+, \d+, 1\)$/)
}

for (const utility of [
  'bg-opacity-',
  'text-opacity-',
  'ring-opacity-',
  'border-opacity-',
]) {
  assert.equal(
    source.includes(utility),
    false,
    `legacy ${utility} utility remains`,
  )
}

for (const utility of [
  'bg-gray-700/25',
  'bg-gray-500/75',
  'ring-black/5',
  'text-current/90',
  'bg-[rgba(var(--color-primary-300),0.25)]',
  'text-[rgba(var(--color-primary-400),0.9)]',
]) {
  assert.equal(
    source.includes(utility),
    true,
    `missing migrated utility ${utility}`,
  )
}

const uuidSource = await read('resources/scripts/helpers/uuid.js')
const directUuidCallers = scripts.filter(
  (file) => file !== 'resources/scripts/helpers/uuid.js',
)
for (const file of directUuidCallers) {
  assert.equal(
    (await read(file)).includes('crypto.randomUUID'),
    false,
    `${file} still calls crypto.randomUUID directly`,
  )
}
const expectedUuidCallers = [
  'resources/scripts/admin/components/estimate-invoice-common/CreateItemRow.vue',
  'resources/scripts/admin/components/estimate-invoice-common/CreateTotal.vue',
  'resources/scripts/admin/components/modal-components/TaxTypeModal.vue',
  'resources/scripts/admin/stores/estimate.js',
  'resources/scripts/admin/stores/invoice.js',
  'resources/scripts/admin/stores/recurring-invoice.js',
  'resources/scripts/admin/stub/estimate.js',
  'resources/scripts/admin/stub/invoice.js',
  'resources/scripts/admin/stub/recurring-invoice.js',
  'resources/scripts/admin/views/settings/customization/NumberCustomizer.vue',
  'resources/scripts/components/base/BaseCheckbox.vue',
  'resources/scripts/components/base/BaseRadio.vue',
  'resources/scripts/stores/notification.js',
]
const actualUuidCallers = []
for (const file of scripts) {
  const content = await read(file)
  if (file !== 'resources/scripts/helpers/uuid.js' && content.includes('createUuid')) {
    actualUuidCallers.push(file)
  }
}
assert.deepEqual(
  actualUuidCallers.sort(),
  [...expectedUuidCallers].sort(),
  'UUID callers changed without updating the shared owner coverage',
)
for (const file of expectedUuidCallers) {
  const content = await read(file)
  assert.match(
    content,
    /import createUuid from ['"]@\/scripts\/helpers\/uuid['"]/,
    `${file} must import the shared UUID helper`,
  )
  assert.match(
    content,
    /createUuid\(\)/,
    `${file} must invoke the shared UUID helper`,
  )
}
const { default: createUuid } = await import(
  `data:text/javascript,${encodeURIComponent(uuidSource)}`
)
const cryptoDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'crypto')

try {
  Object.defineProperty(globalThis, 'crypto', {
    configurable: true,
    value: {
      getRandomValues(bytes) {
        bytes.forEach((_, index) => {
          bytes[index] = index
        })
        return bytes
      },
    },
  })
  const fallbackUuid = createUuid()
  assert.match(
    fallbackUuid,
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
  )

  Object.defineProperty(globalThis, 'crypto', {
    configurable: true,
    value: { randomUUID: () => 'native-uuid' },
  })
  assert.equal(createUuid(), 'native-uuid')
} finally {
  if (cryptoDescriptor) {
    Object.defineProperty(globalThis, 'crypto', cryptoDescriptor)
  } else {
    delete globalThis.crypto
  }
}

const expectedStoreIds = {
  'resources/scripts/admin/stores': [
    'auth',
    'backup',
    'category',
    'company',
    'custom-field',
    'customer',
    'dashboard',
    'disk',
    'estimate',
    'exchange-rate',
    'expense',
    'global',
    'installation',
    'invoice',
    'item',
    'mail-driver',
    'modules',
    'notes',
    'payment',
    'recurring-invoice',
    'reset',
    'role',
    'taxType',
    'user',
    'users',
  ],
  'resources/scripts/customer/stores': [
    'CustomerPortalGlobalStore',
    'customerAuth',
    'customerEstimateStore',
    'customerInvoiceStore',
    'customerPaymentStore',
    'customerUserStore',
    'customers',
    'dashboard',
  ],
  'resources/scripts/stores': ['dialog', 'modal', 'notification'],
}
for (const [directory, expectedIds] of Object.entries(expectedStoreIds)) {
  const actualIds = []
  for (const file of await sourceFiles(directory)) {
    const content = await read(file)
    assert.equal(
      content.includes('defineStore({'),
      false,
      `${file} uses legacy store syntax`,
    )
    const id = content.match(
      /(?:defineStoreFunc|defineStore)\(['"]([^'"]+)['"],/,
    )
    assert.ok(id, `${file} must preserve a string Pinia store id`)
    actualIds.push(id[1])
  }
  assert.deepEqual(
    actualIds.sort(),
    [...expectedIds].sort(),
    `${directory} changed its Pinia store id set`,
  )
}

const baseIcon = await read('resources/scripts/components/base/BaseIcon.vue')
const aliases = Object.fromEntries(
  [...baseIcon.matchAll(/^\s+(\w+): '(\w+)',/gm)].map(([, from, to]) => [
    from,
    to,
  ]),
)
const configuredIcons = [
  ...new Set(
    [...(await read('config/crater.php')).matchAll(/'icon'\s*=>\s*'([^']+)'/g)]
      .map(([, name]) => name)
      .filter(Boolean),
  ),
]
const heroIcons = await read('node_modules/@heroicons/vue/24/outline/index.js')
const hasIconExport = (name) =>
  heroIcons.includes(`module.exports.${name} = require(`)

for (const target of Object.values(aliases)) {
  assert.equal(
    hasIconExport(target),
    true,
    `missing Heroicons export ${target}`,
  )
}

assert.ok(configuredIcons.length > 0, 'config/crater.php must define icons')
for (const name of configuredIcons) {
  assert.equal(
    hasIconExport(aliases[name] ?? name),
    true,
    `configured icon ${name} has no Heroicons export`,
  )
}

for (const [, name] of source.matchAll(
  /<BaseIcon\b[^>]*(?<!:)name="([^"]+)"/g,
)) {
  assert.equal(
    hasIconExport(aliases[name] ?? name),
    true,
    `unresolved BaseIcon name ${name}`,
  )
}

assert.equal(source.includes(':name="item.icon"'), true)
assert.equal(baseIcon.includes('heroIcons[iconName(name)]'), true)
assert.equal(source.includes('@heroicons/vue/outline'), false)

const builtAssets = (
  await Promise.all(
    (await readdir(path.join(root, 'public/build/assets')))
      .filter((file) => file.endsWith('.css') || file.endsWith('.js'))
      .map((file) =>
        readFile(path.join(root, 'public/build/assets', file), 'utf8'),
      ),
  )
).join('\n')
function emittedComponent(marker, name, length = 1200) {
  const start = builtAssets.indexOf(marker)
  assert.notEqual(start, -1, `${name} is missing from emitted assets`)
  return builtAssets.slice(start, start + length)
}

assert.equal(builtAssets.includes('rgba(rgba('), false)
assert.equal(builtAssets.includes('var(--tw-text-opacity)'), false)
assert.match(
  builtAssets,
  /\.text-current\\\/90\{color:currentColor\}/,
  'emitted text opacity utility is missing',
)
assert.match(
  builtAssets,
  /color-mix\(in oklab, currentcolor 90%, transparent\)/,
  'emitted text opacity value is invalid',
)
assert.match(
  builtAssets,
  /\.bg-gray-700\\\/25\{background-color:[^}]*\/\.25\)\}/,
  'emitted background opacity utility is missing or invalid',
)
assert.match(
  builtAssets,
  /\.border-gray-200\\\/60\{border-color:[^}]*\/\.6\)\}/,
  'emitted border opacity utility is missing or invalid',
)
assert.match(
  builtAssets,
  /\.ring-black\\\/5\{--tw-ring-color:[^}]*\/\.05\)\}/,
  'emitted ring opacity utility is missing or invalid',
)
assert.equal(
  builtAssets.includes(
    '.bg-\\[rgba\\(var\\(--color-primary-300\\)\\,0\\.25\\)\\]{background-color:rgba(var(--color-primary-300),.25)}',
  ),
  true,
  'emitted custom primary background is missing or invalid',
)
assert.equal(
  builtAssets.includes(
    '.text-\\[rgba\\(var\\(--color-primary-400\\)\\,0\\.9\\)\\]{color:rgba(var(--color-primary-400),.9)}',
  ),
  true,
  'emitted custom primary text is missing or invalid',
)
assert.match(
  builtAssets,
  /\.from-primary-500\{--tw-gradient-from:rgba\(var\(--color-primary-500\), 1\)/,
  'emitted primary gradient start is missing or invalid',
)
assert.match(
  builtAssets,
  /\.to-primary-400\{--tw-gradient-to:rgba\(var\(--color-primary-400\), 1\)/,
  'emitted primary gradient end is missing or invalid',
)
assert.match(
  builtAssets,
  /\.backdrop-blur\{--tw-backdrop-blur:blur\(8px\);[^}]*backdrop-filter:/,
  'emitted backdrop declaration is missing or invalid',
)

const loginBackground = emittedComponent(
  'M116.21 472.5C55.1239 693.5 78.5219 837.5 114.349 1023H1030.5V-1L0 -106C147.5 21.5 172.311 269.536 116.21 472.5Z',
  'LoginBackground',
)
assert.match(
  loginBackground,
  /stop-color.*rgba\(var\(--color-primary-500\), 1\)/,
  'LoginBackground primary-500 alpha is missing or invalid',
)
assert.match(
  loginBackground,
  /stop-color.*rgba\(var\(--color-primary-400\), 1\)/,
  'LoginBackground primary-400 alpha is missing or invalid',
)

const loginBackgroundOverlay = emittedComponent(
  'M226.002 466.5C164.935 687.5 188.326 831.5 224.141 1017H1140V-7L0 -109.5C142.5 -7.5 282.085 263.536 226.002 466.5Z',
  'LoginBackgroundOverlay',
)
assert.match(
  loginBackgroundOverlay,
  /"fill-opacity":`0\.1`/,
  'LoginBackgroundOverlay opacity is missing or invalid',
)
assert.match(
  loginBackgroundOverlay,
  /stop-color.*rgba\(var\(--color-primary-500\), 1\)/,
  'LoginBackgroundOverlay primary-500 alpha is missing or invalid',
)
assert.match(
  loginBackgroundOverlay,
  /stop-color.*rgba\(var\(--color-primary-400\), 1\)/,
  'LoginBackgroundOverlay primary-400 alpha is missing or invalid',
)

const mainLogo = emittedComponent('__name:`MainLogo`', 'MainLogo')
assert.match(
  mainLogo,
  /darkColor:\{type:String,default:`rgba\(var\(--color-primary-500\), 1\)`\}/,
  'MainLogo primary-500 alpha is missing or invalid',
)
assert.match(
  mainLogo,
  /lightColor:\{type:String,default:`rgba\(var\(--color-primary-400\), 1\)`\}/,
  'MainLogo primary-400 alpha is missing or invalid',
)
console.log('Frontend regression checks passed')
