<template>
  <component :is="heroIcons[iconName(name)]" v-if="isLoaded" class="h-5 w-5" />
</template>

<script setup>
import { ref, onMounted } from 'vue'
import * as heroIcons from '@heroicons/vue/24/outline'

const isLoaded = ref(false)

const iconAliases = {
  BanIcon: 'NoSymbolIcon',
  ChartSquareBarIcon: 'ChartBarSquareIcon',
  CloudDownloadIcon: 'CloudArrowDownIcon',
  CloudUploadIcon: 'CloudArrowUpIcon',
  CogIcon: 'Cog6ToothIcon',
  CashIcon: 'BanknotesIcon',
  ClipboardCheckIcon: 'ClipboardDocumentCheckIcon',
  ClipboardListIcon: 'ClipboardDocumentListIcon',
  DatabaseIcon: 'CircleStackIcon',
  DotsHorizontalIcon: 'EllipsisHorizontalIcon',
  DownloadIcon: 'ArrowDownTrayIcon',
  ExclamationIcon: 'ExclamationTriangleIcon',
  EyeOffIcon: 'EyeSlashIcon',
  FilterIcon: 'FunnelIcon',
  MailIcon: 'EnvelopeIcon',
  LogoutIcon: 'ArrowLeftOnRectangleIcon',
  MenuIcon: 'Bars3Icon',
  OfficeBuildingIcon: 'BuildingOfficeIcon',
  PencilAltIcon: 'PencilIcon',
  PhotographIcon: 'PhotoIcon',
  PlusSmIcon: 'PlusSmallIcon',
  PuzzleIcon: 'PuzzlePieceIcon',
  RefreshIcon: 'ArrowPathIcon',
  SaveIcon: 'ArrowDownTrayIcon',
  SearchIcon: 'MagnifyingGlassIcon',
  SelectorIcon: 'ChevronUpDownIcon',
  SortAscendingIcon: 'BarsArrowUpIcon',
  SortDescendingIcon: 'BarsArrowDownIcon',
  UserAddIcon: 'UserPlusIcon',
  XIcon: 'XMarkIcon',
}

const props = defineProps({
  name: {
    type: String,
    required: true,
  },
})

onMounted(() => {
  isLoaded.value = true
})

const iconName = (name) => iconAliases[name] ?? name
</script>
