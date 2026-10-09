<router-link :to="{ name: 'nova-logs-view' }" class="flex items-center font-normal dim text-white mb-6 text-base no-underline">
    <svg class="sidebar-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M6 3h8l4 4v14H6z" />
    </svg>
    <span class="sidebar-label">{{ __('Visibilidade de logs') }}</span>
</router-link>
