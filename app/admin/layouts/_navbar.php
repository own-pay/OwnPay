    <!-- ========== NAVBAR ========== -->
    <nav class="op-navbar">
        <div class="flex items-center justify-between px-3 py-3 lg:px-5 lg:pl-3">
            <div class="flex items-center">
                <!-- Mobile sidebar toggle -->
                <button id="op-sidebar-toggle" type="button" data-drawer-target="op-sidebar" data-drawer-toggle="op-sidebar" aria-controls="op-sidebar" aria-label="Toggle sidebar"
                    class="inline-flex items-center p-2 text-sm text-gray-500 rounded-lg md:hidden hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:focus:ring-gray-600">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path clip-rule="evenodd" fill-rule="evenodd" d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 10.5a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5a.75.75 0 01-.75-.75zM2 10a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 10z"></path>
                    </svg>
                </button>
                <!-- Logo -->
                <a href="#" class="flex ms-2 md:me-24"
                    data-op-nav-title="Dashboard" data-op-nav-url="<?php echo $site_url . $path_admin ?>/dashboard" data-op-nav-id="nav-item-dashboard">
                    <img src="<?= $OwnPay_logo_dark ?? $OwnPay_logo_light ?? '' ?>" class="h-8 hidden dark:block" alt="OwnPay">
                    <img src="<?= $OwnPay_logo_light ?? '' ?>" class="h-8 dark:hidden" alt="OwnPay">
                </a>

                <!-- Search Bar -->
                <div class="hidden lg:flex items-center ml-8">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg>
                        <input type="text" placeholder="Search transactions, customers, settings..."
                            class="pl-10 pr-4 py-2 w-80 text-sm rounded-lg bg-gray-100 dark:bg-gray-800 border-transparent dark:border-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 transition-all">
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <!-- Mobile Search Toggle -->
                <button type="button" id="op-mobile-search-toggle"
                    class="lg:hidden p-2 text-gray-500 rounded-lg hover:text-gray-900 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-white dark:hover:bg-gray-700" aria-label="Search">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg>
                </button>

                <!-- Dark mode toggle -->
                <button type="button" data-op-action="theme-toggle" aria-label="Toggle dark mode"
                    class="p-2 text-gray-500 rounded-lg hover:text-gray-900 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-white dark:hover:bg-gray-700 focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600">
                    <!-- Sun icon (shown in dark mode) -->
                    <svg data-theme-toggle-light class="w-5 h-5 hidden" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path>
                    </svg>
                    <!-- Moon icon (shown in light mode) -->
                    <svg data-theme-toggle-dark class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
                    </svg>
                </button>

                <!-- User menu dropdown -->
                <div class="relative">
                    <button type="button" id="op-user-menu-btn" data-dropdown-toggle="op-user-dropdown"
                        class="flex items-center gap-2 text-sm rounded-full focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600 p-1">
                        <img class="w-8 h-8 rounded-full"
                            src="https://ui-avatars.com/api/?name=<?php echo getNameChars($global_user_response['response'][0]['full_name'], 2); ?>&color=FFFFFF&background=6d28d9&size=64"
                            alt="User">
                        <span class="hidden xl:block text-left">
                            <span class="block text-sm font-medium text-gray-900 dark:text-white truncate max-w-[100px]">
                                <?php echo htmlspecialchars($global_user_response['response'][0]['full_name']) ?>
                            </span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                <?php echo ucfirst($global_user_response['response'][0]['role']) ?>
                            </span>
                        </span>
                    </button>

                    <!-- Dropdown -->
                    <div id="op-user-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-100 dark:bg-gray-800 dark:border-gray-700 z-50">
                        <div class="py-1">
                            <a href="#"
                                data-op-nav-title="My Account" data-op-nav-url="<?php echo $site_url . $path_admin ?>/my-account" data-op-nav-id="nav-menu-my-account" data-op-close="op-user-dropdown"
                                class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /></svg>
                                My Account
                            </a>
                            <a href="#"
                                data-op-nav-title="Activities" data-op-nav-url="<?php echo $site_url . $path_admin ?>/activities" data-op-nav-id="nav-item-activities" data-op-close="op-user-dropdown"
                                class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8l4 -16l3 8h4" /></svg>
                                Activities
                            </a>
                            <div class="border-t border-gray-100 dark:border-gray-700"></div>
                            <a href="<?php echo $site_url . $path_admin ?>/?logout"
                                class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-gray-100 dark:text-red-400 dark:hover:bg-gray-700">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" /></svg>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Search Bar (expandable) -->
        <div id="op-mobile-search" class="hidden lg:hidden px-3 pb-3 border-t border-gray-200/10">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg>
                <input type="text" id="op-mobile-search-input" placeholder="Search transactions, customers, settings..."
                    class="pl-10 pr-4 py-2 w-full text-sm rounded-lg bg-gray-100 dark:bg-gray-800 border-transparent dark:border-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 transition-all">
            </div>
        </div>
    </nav>