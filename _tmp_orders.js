
// 4-Step Order Wizard Functions
let currentStep = 1;
const totalSteps = 4;

// Get customer data for review
const customersData = <?php echo json_encode($customers); ?>;
const dishesData = <?php echo json_encode($dishes); ?>;
window.dishesData = dishesData;
const dishIngredientsByDishId = <?php echo json_encode($dish_ingredients_for_form ?: new stdClass()); ?>;

function updateDishThumb(row) {
    if (!row) return;
    const dishSelect = row.querySelector('.dish-select');
    const img = row.querySelector('.dish-thumb-img');
    const placeholder = row.querySelector('.dish-thumb-placeholder');
    if (!dishSelect || !img || !placeholder) return;

    const dishId = dishSelect.value;
    let imageUrl = '';
    if (dishId && Array.isArray(dishesData)) {
        const dish = dishesData.find(d => String(d.id) === String(dishId));
        imageUrl = (dish && dish.image_url) ? dish.image_url : '';
    }
    if (!imageUrl && dishId) {
        imageUrl = '../api/dish_image.php?id=' + encodeURIComponent(dishId);
    }

    if (dishId && imageUrl) {
        placeholder.style.display = 'none';
        img.style.display = 'block';
        img.onload = function () {
            placeholder.style.display = 'none';
            img.style.display = 'block';
        };
        img.onerror = function () {
            img.style.display = 'none';
            placeholder.style.display = 'flex';
        };
        img.src = imageUrl;
    } else {
        img.removeAttribute('src');
        img.style.display = 'none';
        placeholder.style.display = 'flex';
    }
}
window.updateDishThumb = updateDishThumb;

function renderDishIngredientsPanel(row, preRemovedIds) {
    if (!row) return;
    const panel = row.querySelector('.dish-ingredients-panel');
    const list = row.querySelector('.dish-ingredients-list');
    const dishSelect = row.querySelector('.dish-select');
    if (!panel || !list || !dishSelect) return;

    const rowIndex = row.getAttribute('data-row') || '0';
    const dishId = String(dishSelect.value || '');
    const ingredients = (dishId && dishIngredientsByDishId[dishId]) ? dishIngredientsByDishId[dishId] : [];

    const removedSet = new Set((preRemovedIds || []).map(Number).filter(Boolean));
    list.querySelectorAll('input.dish-removed-ingredient').forEach(function (inp) {
        removedSet.add(Number(inp.value));
    });

    list.innerHTML = '';
    if (!dishId || ingredients.length === 0) {
        panel.style.display = 'none';
        return;
    }

    panel.style.display = 'block';
    ingredients.forEach(function (ing) {
        const id = Number(ing.ingredient_id);
        const isRemoved = removedSet.has(id);
        const qty = ing.quantity != null ? ing.quantity : '';
        const unit = ing.unit || '';
        const labelText = (ing.ingredient_name || 'Ingredient') + (qty !== '' ? (' (' + qty + (unit ? ' ' + unit : '') + ')') : '');

        const chip = document.createElement('div');
        chip.className = 'd-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill border ' +
            (isRemoved ? 'bg-danger-subtle text-decoration-line-through text-muted' : 'bg-light');
        chip.style.fontSize = '0.85rem';

        const nameSpan = document.createElement('span');
        nameSpan.textContent = (isRemoved ? 'Removed: ' : '') + labelText;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm ' + (isRemoved ? 'btn-outline-success' : 'btn-outline-danger');
        btn.style.padding = '0 0.4rem';
        btn.style.fontSize = '0.75rem';
        btn.textContent = isRemoved ? 'Undo' : 'Remove';

        let hidden = null;
        if (isRemoved) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.className = 'dish-removed-ingredient';
            hidden.name = 'dishes[' + rowIndex + '][removed_ingredients][]';
            hidden.value = String(id);
            chip.appendChild(hidden);
        }

        btn.addEventListener('click', function () {
            if (hidden) {
                // Undo remove
                hidden.remove();
                hidden = null;
                chip.className = 'd-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill border bg-light';
                nameSpan.textContent = labelText;
                btn.className = 'btn btn-sm btn-outline-danger';
                btn.style.padding = '0 0.4rem';
                btn.style.fontSize = '0.75rem';
                btn.textContent = 'Remove';
            } else {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.className = 'dish-removed-ingredient';
                hidden.name = 'dishes[' + rowIndex + '][removed_ingredients][]';
                hidden.value = String(id);
                chip.insertBefore(hidden, nameSpan);
                chip.className = 'd-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill border bg-danger-subtle text-decoration-line-through text-muted';
                nameSpan.textContent = 'Removed: ' + labelText;
                btn.className = 'btn btn-sm btn-outline-success';
                btn.style.padding = '0 0.4rem';
                btn.style.fontSize = '0.75rem';
                btn.textContent = 'Undo';
            }
        });

        chip.appendChild(nameSpan);
        chip.appendChild(btn);
        list.appendChild(chip);
    });
}

window.renderDishIngredientsPanel = renderDishIngredientsPanel;

// Validate form submission - ensure correct mode is set
function validateFormSubmission() {
    const createOrderInput = document.getElementById('createOrderInput');
    const updateOrderInput = document.getElementById('updateOrderInput');
    const editOrderNumberInput = document.getElementById('editOrderNumber');
    
    // Check if we're in update mode by checking if updateOrderInput has name attribute
    const isUpdateMode = updateOrderInput && updateOrderInput.hasAttribute('name') && updateOrderInput.getAttribute('name') === 'update_order';
    
    if (isUpdateMode) {
        // UPDATE MODE: Editing existing order
        // Ensure order_number is set and has value
        if (!editOrderNumberInput || !editOrderNumberInput.value || !editOrderNumberInput.hasAttribute('name')) {
            alert('Error: Order number is missing. Please click the Edit button again to load the order.');
            return false;
        }
        
        // Ensure update_order input has name attribute and value
        if (!updateOrderInput.hasAttribute('name')) {
            updateOrderInput.setAttribute('name', 'update_order');
            updateOrderInput.value = '1';
        }
        
        // CRITICAL: Remove create_order input completely so it doesn't interfere
        if (createOrderInput && createOrderInput.hasAttribute('name')) {
            createOrderInput.removeAttribute('name');
            createOrderInput.value = '';
        }
        
        console.log('Submitting form in UPDATE mode. Order number:', editOrderNumberInput.value);
        return true;
    } else {
        // CREATE MODE: Creating new order
        // Ensure create_order input has name attribute
        if (createOrderInput) {
            createOrderInput.setAttribute('name', 'create_order');
            createOrderInput.value = '1';
        }
        
        // CRITICAL: Remove update_order and order_number inputs completely so they don't interfere
        if (updateOrderInput && updateOrderInput.hasAttribute('name')) {
            updateOrderInput.removeAttribute('name');
            updateOrderInput.value = '';
        }
        
        if (editOrderNumberInput && editOrderNumberInput.hasAttribute('name')) {
            editOrderNumberInput.removeAttribute('name');
            editOrderNumberInput.value = '';
        }

        const customerCell = (document.getElementById('customer_cell')?.value || '').trim();
        const persons = parseInt(document.getElementById('number_of_persons')?.value || '0', 10);
        const shift = document.getElementById('shift')?.value || '';
        const deliveryDate = document.getElementById('delivery_date')?.value || '';
        const deliveryTime = document.getElementById('delivery_time')?.value || '';
        if (!customerCell || persons <= 0 || !shift || !deliveryDate || !deliveryTime) {
            alert('Please fill all required customer fields in Step 1.');
            return false;
        }

        let hasDish = false;
        document.querySelectorAll('.dish-row').forEach(function (row) {
            const dishId = row.querySelector('.dish-select')?.value;
            const qty = parseFloat(row.querySelector('.dish-quantity')?.value || '0');
            if (dishId && qty > 0) {
                hasDish = true;
            }
        });
        if (!hasDish) {
            alert('Please select at least one dish with quantity in Step 2.');
            return false;
        }
        
        console.log('Submitting form in CREATE mode (new order)');
        return true;
    }
}

// Reset form to create mode
function resetFormToCreateMode() {
    const createOrderInput = document.getElementById('createOrderInput');
    const updateOrderInput = document.getElementById('updateOrderInput');
    const editOrderNumberInput = document.getElementById('editOrderNumber');
    const submitButton = document.getElementById('orderSubmitButton');
    const submitButtonText = document.getElementById('submitButtonText');
    
    if (createOrderInput && updateOrderInput && editOrderNumberInput) {
        // CREATE MODE: Set create_order input properly
        createOrderInput.setAttribute('name', 'create_order');
        createOrderInput.value = '1';
        createOrderInput.style.display = 'block';
        
        // CREATE MODE: Remove update_order input completely
        updateOrderInput.removeAttribute('name');
        updateOrderInput.value = '';
        updateOrderInput.style.display = 'none';
        
        // CREATE MODE: Remove order_number input completely (not needed for new orders)
        editOrderNumberInput.removeAttribute('name');
        editOrderNumberInput.value = '';
        
        console.log('Form reset to CREATE mode (new order)');
    }
    
    if (submitButton && submitButtonText) {
        submitButtonText.textContent = '<?php echo addslashes(t("create_order")); ?>';
        submitButton.classList.remove('btn-warning');
        submitButton.classList.add('btn-success');
    }
    
    const formTitle = document.getElementById('formTitle');
    const newOrderBtn = document.getElementById('newOrderBtn');
    
    if (formTitle) {
        formTitle.textContent = '<?php echo addslashes(t("create_order")); ?> - 4-Step Process';
    }
    
    if (newOrderBtn) {
        // Keep New Order available so user can always start fresh
        newOrderBtn.style.display = 'inline-block';
    }
}

// Edit Order Function
function editOrder(orderNumber) {
    // Wait for ordersData to be available
    if (typeof ordersData === 'undefined' || !ordersData || ordersData.length === 0) {
        alert('Order data not available. Please wait a moment and try again.');
        return;
    }
    
    // Find the order
    const order = ordersData.find(o => o.order_number == orderNumber || o.id == orderNumber);
    if (!order) {
        alert('Order not found.');
        return;
    }
    
    // Get the actual order number from the order object
    const actualOrderNumber = order.order_number || orderNumber;
    
    if (!actualOrderNumber) {
        alert('Order number not found in order data.');
        return;
    }
    
    // Scroll to the order form
    const orderForm = document.getElementById('orderForm');
    if (orderForm) {
        orderForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        
        // Populate Step 1: Customer Information
        const customerName = document.getElementById('customer_name');
        const customerCell = document.getElementById('customer_cell');
        const numberOfPersons = document.getElementById('number_of_persons');
        const shift = document.getElementById('shift');
        const deliveryDate = document.getElementById('delivery_date');
        const deliveryTime = document.getElementById('delivery_time');
        
        if (customerName) customerName.value = order.customer_name || '';
        if (customerCell) customerCell.value = order.customer_cell || '';
        if (numberOfPersons) numberOfPersons.value = order.number_of_persons || '';
        if (shift) shift.value = order.shift || '';
        if (deliveryDate) {
            const date = order.delivery_date ? order.delivery_date.split(' ')[0] : '';
            deliveryDate.value = date;
        }
        if (deliveryTime) {
            const time = order.delivery_time || '';
            deliveryTime.value = time;
        }
        const advanceInput = document.getElementById('advance_amount');
        if (advanceInput) {
            advanceInput.value = order.advance_amount != null ? order.advance_amount : 0;
        }
        
        // Populate Step 2: Dishes
        const dishesContainer = document.getElementById('dishesContainer');
        if (dishesContainer && order.dishes && order.dishes.length > 0) {
            // Clear existing dish rows except the first one
            const existingRows = dishesContainer.querySelectorAll('.dish-row');
            for (let i = 1; i < existingRows.length; i++) {
                existingRows[i].remove();
            }
            
            // Populate dishes
            order.dishes.forEach(function(dish, index) {
                let row;
                if (index === 0) {
                    // Use first row
                    row = dishesContainer.querySelector('.dish-row');
                } else {
                    // Add new row
                    const addBtn = document.getElementById('addDishBtn');
                    if (addBtn) {
                        addBtn.click();
                        row = dishesContainer.querySelectorAll('.dish-row')[index];
                    } else {
                        return;
                    }
                }
                
                if (row) {
                    const dishSelect = row.querySelector('.dish-select');
                    const unitSelect = row.querySelector('.dish-unit');
                    const quantityInput = row.querySelector('.dish-quantity');
                    const unitPriceInput = row.querySelector('.dish-unit-price');
                    const totalAmountInput = row.querySelector('.dish-total-amount');
                    
                    // Set dish
                    if (dishSelect && dish.dish_id) {
                        dishSelect.value = dish.dish_id;
                        // Trigger change to populate unit dropdown
                        if (typeof updateUnitDropdown === 'function') {
                            updateUnitDropdown(dishSelect);
                            // Set unit after dropdown is populated
                            setTimeout(function() {
                                if (unitSelect && dish.unit) {
                                    unitSelect.value = dish.unit;
                                }
                                if (typeof renderDishIngredientsPanel === 'function') {
                                    renderDishIngredientsPanel(row, dish.removed_ingredient_ids || []);
                                }
                            }, 200);
                        } else {
                            // If updateUnitDropdown not available yet, initialize unit dropdown manually
                            if (unitSelect && typeof initializeUnitDropdowns === 'function') {
                                initializeUnitDropdowns();
                                setTimeout(function() {
                                    if (unitSelect && dish.unit) {
                                        unitSelect.value = dish.unit;
                                    }
                                    if (typeof renderDishIngredientsPanel === 'function') {
                                        renderDishIngredientsPanel(row, dish.removed_ingredient_ids || []);
                                    }
                                }, 100);
                            }
                        }
                    }
                    
                    // Set quantity
                    if (quantityInput) quantityInput.value = dish.quantity || '';
                    
                    // Set unit price and total amount
                    if (unitPriceInput && dish.total_amount && dish.quantity) {
                        const unitPrice = parseFloat(dish.total_amount) / parseFloat(dish.quantity);
                        unitPriceInput.value = unitPrice.toFixed(2);
                    }
                    if (totalAmountInput) totalAmountInput.value = dish.total_amount || '';
                }
            });
        }
        
        // Populate Step 3: Compulsory Items (Additional Items)
        if (order.extra_ingredients && order.extra_ingredients.additional_items) {
            const additionalItems = order.extra_ingredients.additional_items;
            Object.keys(additionalItems).forEach(function(itemKey) {
                const input = document.querySelector(`input[name="additional_items[${itemKey}]"]`);
                if (input) {
                    input.value = additionalItems[itemKey] || 0;
                }
            });
        }
        
        // Show Step 1
        document.querySelectorAll('.order-step').forEach(function(step) {
            step.style.display = 'none';
        });
        const step1 = document.getElementById('step1');
        if (step1) {
            step1.style.display = 'block';
            currentStep = 1;
        }
        
        // Update progress indicator
        updateProgressIndicator(0, 1);
        
        // Set form to update mode
        const createOrderInput = document.getElementById('createOrderInput');
        const updateOrderInput = document.getElementById('updateOrderInput');
        const editOrderNumberInput = document.getElementById('editOrderNumber');
        
        if (createOrderInput && updateOrderInput && editOrderNumberInput) {
            // EDIT MODE: Remove create_order input completely
            createOrderInput.removeAttribute('name');
            createOrderInput.value = '';
            
            // EDIT MODE: Set update_order input properly
            updateOrderInput.setAttribute('name', 'update_order');
            updateOrderInput.value = '1';
            updateOrderInput.style.display = 'block';
            
            // EDIT MODE: Set order number - use actualOrderNumber from order object
            editOrderNumberInput.setAttribute('name', 'order_number');
            editOrderNumberInput.value = actualOrderNumber;
            
            console.log('Form switched to UPDATE/EDIT mode. Order number:', actualOrderNumber);
        } else {
            console.error('Form elements not found:', {
                createOrderInput: !!createOrderInput,
                updateOrderInput: !!updateOrderInput,
                editOrderNumberInput: !!editOrderNumberInput
            });
        }
        
        // Update form submit button text if it exists
        const submitButton = document.getElementById('orderSubmitButton');
        const submitButtonText = document.getElementById('submitButtonText');
        const formTitle = document.getElementById('formTitle');
        const newOrderBtn = document.getElementById('newOrderBtn');
        
        if (submitButton && submitButtonText) {
            submitButtonText.textContent = 'Update Order';
            submitButton.classList.remove('btn-success');
            submitButton.classList.add('btn-warning');
        }
        
        if (formTitle) {
            formTitle.textContent = 'Edit Order - 4-Step Process';
        }
        
        if (newOrderBtn) {
            newOrderBtn.style.display = 'inline-block';
        }
        
        // Show success message
        alert('Order loaded for editing. Please review and update the information, then click "Update Order" to save changes.');
    }
}

// Step navigation functions
function nextStep(step) {
    // Initialize unit dropdowns before validation
    if (currentStep === 2) {
        initializeUnitDropdowns();
    }
    
    if (validateCurrentStep()) {
        if (step <= totalSteps) {
            // Hide current step
            document.getElementById('step' + currentStep).style.display = 'none';
            
            // Update progress indicator
            updateProgressIndicator(currentStep, step);
            
            // Show next step
            currentStep = step;
            document.getElementById('step' + currentStep).style.display = 'block';
            
            // If moving to step 4, update review
            if (step === 4) {
                updateReview();
            }
        }
    }
}

function previousStep(step) {
    if (step >= 1) {
        // Hide current step
        document.getElementById('step' + currentStep).style.display = 'none';
        
        // Update progress indicator
        updateProgressIndicator(currentStep, step);
        
        // Show previous step
        currentStep = step;
        document.getElementById('step' + currentStep).style.display = 'block';
    }
}

// Tab switching function for dish selection
function showDishTab(tab) {
    const tabAll = document.getElementById('tabAll');
    const tabPrevious = document.getElementById('tabPrevious');
    const previousSection = document.getElementById('previousDishesSection');
    
    if (tab === 'all') {
        if (tabAll) tabAll.classList.add('active');
        if (tabPrevious) tabPrevious.classList.remove('active');
        if (previousSection) previousSection.style.display = 'none';
    } else if (tab === 'previous') {
        if (tabAll) tabAll.classList.remove('active');
        if (tabPrevious) tabPrevious.classList.add('active');
        if (previousSection) previousSection.style.display = 'block';
    }
}

// Add event listeners for previously used dishes quick select
document.addEventListener('DOMContentLoaded', function() {
    const previousDishBtns = document.querySelectorAll('.previous-dish-btn');
    previousDishBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const dishId = this.dataset.dishId;
            const dishName = this.dataset.dishName;
            
            // Find the first empty dish row or add a new one
            const dishRows = document.querySelectorAll('.dish-row');
            let targetRow = null;
            
            for (let row of dishRows) {
                const dishSelect = row.querySelector('.dish-select');
                if (dishSelect && !dishSelect.value) {
                    targetRow = row;
                    break;
                }
            }
            
            // If no empty row, add a new one
            if (!targetRow) {
                const addBtn = document.getElementById('addDishBtn');
                if (addBtn) {
                    addBtn.click();
                    // Wait a bit for the new row to be added
                    setTimeout(function() {
                        const newRows = document.querySelectorAll('.dish-row');
                        targetRow = newRows[newRows.length - 1];
                        if (targetRow) {
                            const dishSelect = targetRow.querySelector('.dish-select');
                            if (dishSelect) dishSelect.value = dishId;
                        }
                    }, 100);
                }
            } else {
                const dishSelect = targetRow.querySelector('.dish-select');
                if (dishSelect) {
                    dishSelect.value = dishId;
                    // Trigger change event to update any dependent fields
                    dishSelect.dispatchEvent(new Event('change'));
                }
            }
        });
    });
    
    // Update tab styles on hover
    const dishTabs = document.querySelectorAll('.dish-tab');
    dishTabs.forEach(function(tab) {
        tab.addEventListener('mouseenter', function() {
            if (!this.classList.contains('active')) {
                this.style.color = '#6366f1';
                this.style.background = 'rgba(99, 102, 241, 0.05)';
            }
        });
        tab.addEventListener('mouseleave', function() {
            if (!this.classList.contains('active')) {
                this.style.color = '#64748b';
                this.style.background = 'transparent';
            }
        });
    });
    
    // Set active tab styles
    const activeTab = document.querySelector('.dish-tab.active');
    if (activeTab) {
        activeTab.style.color = '#6366f1';
        activeTab.style.borderBottomColor = '#6366f1';
        activeTab.style.background = 'rgba(99, 102, 241, 0.05)';
    }
});

function validateCurrentStep() {
    if (currentStep === 1) {
        // Validate new customer information fields
        const customerName = document.getElementById('customer_name');
        const customerCell = document.getElementById('customer_cell');
        const orderDate = document.getElementById('order_date');
        const orderTime = document.getElementById('order_time');
        const numberOfPersons = document.getElementById('number_of_persons');
        const shift = document.getElementById('shift');
        const deliveryDate = document.getElementById('delivery_date');
        const deliveryTime = document.getElementById('delivery_time');
        
        if (customerCell && !customerCell.value.trim()) {
            alert('Please enter customer cell number');
            customerCell.focus();
            return false;
        }
        if (orderDate && !orderDate.value) {
            alert('Please select order date');
            orderDate.focus();
            return false;
        }
        if (orderTime && !orderTime.value) {
            alert('Please select order time');
            orderTime.focus();
            return false;
        }
        if (numberOfPersons && (!numberOfPersons.value || parseInt(numberOfPersons.value) <= 0)) {
            alert('Please enter number of persons (must be greater than 0)');
            numberOfPersons.focus();
            return false;
        }
        if (shift && !shift.value) {
            alert('براہ کرم شفٹ منتخب کریں (صبح، دوپہر یا شام)');
            shift.focus();
            return false;
        }
        if (deliveryDate && !deliveryDate.value) {
            alert('Please select delivery date');
            deliveryDate.focus();
            return false;
        }
        if (deliveryTime && !deliveryTime.value) {
            alert('Please select delivery time');
            deliveryTime.focus();
            return false;
        }
        return true;
    } else if (currentStep === 2) {
        // Initialize unit dropdowns before validation
        if (typeof initializeUnitDropdowns === 'function') {
            initializeUnitDropdowns();
        }
        
        // Validate at least one dish is added with unit
        const dishRows = document.querySelectorAll('.dish-row');
        let hasValidDish = false;
        
        dishRows.forEach(function(row) {
            const dishSelect = row.querySelector('.dish-select');
            const quantityInput = row.querySelector('.dish-quantity');
            const unitSelect = row.querySelector('.dish-unit');
            
            if (dishSelect.value && quantityInput.value && parseFloat(quantityInput.value) > 0) {
                // If dish is selected, unit must also be selected
                if (unitSelect && unitSelect.value) {
                    hasValidDish = true;
                } else if (dishSelect.value) {
                    // Dish selected but no unit - make unit optional for now, but show warning
                    hasValidDish = true;
                }
            }
        });
        
        if (!hasValidDish) {
            alert('Please add at least one dish with quantity and unit');
            return false;
        }
        
        // Check if any dish has no unit selected
        let missingUnit = false;
        dishRows.forEach(function(row) {
            const dishSelect = row.querySelector('.dish-select');
            const unitSelect = row.querySelector('.dish-unit');
            if (dishSelect.value && (!unitSelect || !unitSelect.value)) {
                missingUnit = true;
                if (unitSelect) {
                    unitSelect.focus();
                }
            }
        });
        
        if (missingUnit) {
            alert('Please select a unit for all selected dishes');
            return false;
        }
        
        return true;
    }
    return true;
}

function updateProgressIndicator(fromStep, toStep) {
    // Update step items and lines
    const progressSteps = document.querySelector('.progress-steps');
    if (!progressSteps) return;
    
    const stepItems = progressSteps.querySelectorAll('.step-item');
    const stepLines = progressSteps.querySelectorAll('.step-line');
    
    stepItems.forEach(function(stepItem, index) {
        const stepNum = index + 1;
        
        if (stepNum < toStep) {
            // Completed steps
            stepItem.classList.remove('active');
            stepItem.classList.add('completed');
            // Mark previous line as completed
            if (index > 0 && stepLines[index - 1]) {
                stepLines[index - 1].classList.add('completed');
            }
        } else if (stepNum === toStep) {
            // Active step
            stepItem.classList.remove('completed');
            stepItem.classList.add('active');
        } else {
            // Future steps
            stepItem.classList.remove('active', 'completed');
        }
    });
    
    // Update step lines
    stepLines.forEach(function(stepLine, index) {
        if (index + 1 < toStep) {
            stepLine.classList.add('completed');
        } else {
            stepLine.classList.remove('completed');
        }
    });
}

function updateReview() {
    // Update customer information - check for new form fields first
    const customerName = document.getElementById('customer_name');
    const customerCell = document.getElementById('customer_cell');
    const numberOfPersons = document.getElementById('number_of_persons');
    const shift = document.getElementById('shift');
    const deliveryDate = document.getElementById('delivery_date');
    const deliveryTime = document.getElementById('delivery_time');
    const reviewCustomer = document.getElementById('reviewCustomer');
    
    if (customerName && customerName.value) {
        // New form fields
        let customerInfo = `
            <div>
                <strong>Customer Name:</strong> ${escapeHtml(customerName.value)}<br>
                <strong>Cell No:</strong> ${escapeHtml(customerCell ? customerCell.value : '')}<br>
                <strong>Number of Persons:</strong> ${escapeHtml(numberOfPersons ? numberOfPersons.value : '')}<br>
                <strong>Delivery Date:</strong> ${escapeHtml(deliveryDate ? deliveryDate.value : '')}<br>
                <strong>شفٹ:</strong> ${escapeHtml(shift ? shift.options[shift.selectedIndex].text : '')}<br>
                <strong>Delivery Time:</strong> ${escapeHtml(deliveryTime ? deliveryTime.value : '')}
            </div>
        `;
        reviewCustomer.innerHTML = customerInfo;
    } else {
        // Old form - check customer selection
        const customerSelect = document.getElementById('customer_id');
        const customerId = customerSelect ? customerSelect.value : '';
        
        if (customerId && customersData) {
            const customer = customersData.find(c => c.id == customerId);
            if (customer) {
                reviewCustomer.innerHTML = `
                    <div class="d-flex align-items-center">
                        <div>
                            <strong>${escapeHtml(customer.name)}</strong><br>
                            <small class="text-muted">${escapeHtml(customer.email)}</small>
                        </div>
                    </div>
                `;
            }
        } else {
            reviewCustomer.innerHTML = '<p class="text-muted mb-0">No customer information provided</p>';
        }
    }
    
    // Update dishes information
    const dishRows = document.querySelectorAll('.dish-row');
    const reviewDishes = document.getElementById('reviewDishes');
    let dishesHTML = '';
    let totalAmount = 0;
    
    if (dishRows && dishesData) {
        dishRows.forEach(function(row) {
            const dishSelect = row.querySelector('.dish-select');
            const unitSelect = row.querySelector('.dish-unit');
            const quantityInput = row.querySelector('.dish-quantity');
            const unitPriceInput = row.querySelector('.dish-unit-price');
            const totalAmountInput = row.querySelector('.dish-total-amount');
            
            if (dishSelect && dishSelect.value && quantityInput && quantityInput.value) {
                const dishId = dishSelect.value;
                const dish = dishesData.find(d => d.id == dishId);
                const quantity = parseFloat(quantityInput.value) || 0;
                const unit = unitSelect ? unitSelect.value : '';
                const unitPrice = parseFloat(unitPriceInput.value) || 0;
                const total = parseFloat(totalAmountInput.value) || (quantity * unitPrice);
                
                if (dish && quantity > 0) {
                    const unitText = unit ? ` ${unit}` : '';
                    const thumb = dish.image_url
                        ? `<img src="${escapeHtml(dish.image_url)}" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:8px;margin-right:10px;" onerror="this.style.display='none'">`
                        : `<div style="width:48px;height:48px;border-radius:8px;margin-right:10px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;"><i class="bi bi-egg-fried text-white"></i></div>`;
                    dishesHTML += `
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background: white; border-radius: 6px;">
                            <div class="d-flex align-items-center">
                                ${thumb}
                                <div>
                                    <strong>${escapeHtml(dish.name)}</strong><br>
                                    <small class="text-muted">Quantity: ${quantity}${unitText} ${unitPrice > 0 ? '× Rs ' + unitPrice.toFixed(2) : ''}</small>
                                </div>
                            </div>
                            <div class="text-end">
                                <strong class="text-primary">Rs ${total.toFixed(2)}</strong>
                            </div>
                        </div>
                    `;
                    totalAmount += total;
                }
            }
        });
    }
    
    // Add extra ingredients to review
    const extraIngredientRows = document.querySelectorAll('.extra-ingredient-row[style*="block"], .extra-ingredient-row:not([style*="none"])');
    const ingredientsData = typeof window.ingredientsData !== 'undefined' ? window.ingredientsData : [];
    
    // Get translations from PHP for review section
    const reviewTranslations = typeof window.reviewTranslations !== 'undefined' ? window.reviewTranslations : {
        extra_ingredients: '<?php echo addslashes(t("extra_ingredients")); ?>',
        additional_items: '<?php echo addslashes(t("additional_items")); ?>',
        quantity: '<?php echo addslashes(t("quantity")); ?>',
        cloth_malmal: '<?php echo addslashes(t("cloth_malmal")); ?>',
        match_box: '<?php echo addslashes(t("match_box")); ?>',
        surrf: '<?php echo addslashes(t("surrf")); ?>',
        wood: '<?php echo addslashes(t("wood", "لکڑی")); ?>',
        sponjis_iron: '<?php echo addslashes(t("sponjis_iron")); ?>',
        sobi_iron: '<?php echo addslashes(t("sobi_iron")); ?>',
        steam_pot_with_lid: '<?php echo addslashes(t("steam_pot_with_lid")); ?>',
        deg: '<?php echo addslashes(t("deg")); ?>',
        karahi: '<?php echo addslashes(t("karahi")); ?>',
        chulhe: '<?php echo addslashes(t("chulhe")); ?>',
        parat: '<?php echo addslashes(t("parat")); ?>',
        tub: '<?php echo addslashes(t("tub")); ?>',
        shamiana: '<?php echo addslashes(t("shamiana")); ?>',
        qanat: '<?php echo addslashes(t("qanat")); ?>',
        dari: '<?php echo addslashes(t("dari")); ?>',
        charpai: '<?php echo addslashes(t("charpai")); ?>',
        coal: '<?php echo addslashes(t("coal")); ?>',
        steam_pot_without_lid: '<?php echo addslashes(t("steam_pot_without_lid")); ?>'
    };
    
    if (extraIngredientRows.length > 0 && ingredientsData.length > 0) {
        let hasExtraIngredients = false;
        let extraIngredientsHTML = '<div class="mt-3 pt-3 border-top"><strong class="text-success"><i class="bi bi-plus-circle me-1"></i>' + reviewTranslations.extra_ingredients + ':</strong></div>';
        
        extraIngredientRows.forEach(function(row) {
            const ingredientSelect = row.querySelector('.extra-ingredient-select');
            const quantityInput = row.querySelector('.extra-ingredient-quantity');
            const unitInput = row.querySelector('.extra-ingredient-unit');
            
            if (ingredientSelect && ingredientSelect.value && quantityInput && quantityInput.value) {
                const ingredientId = ingredientSelect.value;
                const ingredient = ingredientsData.find(i => i.id == ingredientId);
                const quantity = parseFloat(quantityInput.value) || 0;
                const unit = unitInput ? unitInput.value : '';
                
                if (ingredient && quantity > 0) {
                    hasExtraIngredients = true;
                    const unitText = unit ? ' ' + escapeHtml(unit) : '';
                    extraIngredientsHTML += `
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background: #f0fdf4; border-radius: 6px; border-left: 3px solid #10b981;">
                            <div>
                                <strong class="text-success">${escapeHtml(ingredient.name)}</strong><br>
                                <small class="text-muted">${reviewTranslations.quantity}: ${quantity}${unitText}</small>
                            </div>
                        </div>
                    `;
                }
            }
        });
        
        if (hasExtraIngredients) {
            dishesHTML += extraIngredientsHTML;
        }
    }
    
    // Add additional items to review
    const additionalItemInputs = document.querySelectorAll('.additional-item');
    let hasAdditionalItems = false;
    let additionalItemsHTML = '';
    
    additionalItemInputs.forEach(function(input) {
        const quantity = parseInt(input.value) || 0;
        if (quantity > 0) {
            if (!hasAdditionalItems) {
                additionalItemsHTML = '<div class="mt-3 pt-3 border-top"><strong class="text-info"><i class="bi bi-box-seam me-1"></i>' + reviewTranslations.additional_items + ':</strong></div>';
                hasAdditionalItems = true;
            }
            
            // Get the item name from the input name attribute
            const itemName = input.name.match(/\[([^\]]+)\]/);
            let displayName = '';
            let unit = 'عدد'; // Default to pieces
            if (itemName) {
                const key = itemName[1];
                const nameMap = {
                    'cloth_malmal': reviewTranslations.cloth_malmal || 'کپڑا ململ',
                    'match_box': reviewTranslations.match_box || 'ماچس',
                    'surrf': reviewTranslations.surrf || 'سرف',
                    'wood': reviewTranslations.wood || 'لکڑی',
                    'sponjis_iron': reviewTranslations.sponjis_iron || 'اسپنجز (آئرن)',
                    'sobi_iron': reviewTranslations.sobi_iron || 'صوبی(لوہے والی )',
                    'steam_pot_with_lid': reviewTranslations.steam_pot_with_lid || 'سٹیم پتیلہ جال ڈھکن',
                    'deg': reviewTranslations.deg || 'دیگ',
                    'karahi': reviewTranslations.karahi || 'کڑاہی',
                    'chulhe': reviewTranslations.chulhe || 'چولہے',
                    'parat': reviewTranslations.parat || 'پرات',
                    'tub': reviewTranslations.tub || 'ٹب',
                    'shamiana': reviewTranslations.shamiana || 'شامیانہ',
                    'qanat': reviewTranslations.qanat || 'قنات',
                    'dari': reviewTranslations.dari || 'دری',
                    'charpai': reviewTranslations.charpai || 'چارپائی',
                    'coal': reviewTranslations.coal || 'کوئلہ',
                    'steam_pot_without_lid': reviewTranslations.steam_pot_without_lid || 'سٹیم پتیلہ بغیر ڈھکن'
                };
                displayName = nameMap[key] || key;
                
                // Set unit: meter for cloth_malmal, gram for surrf, kilo for wood, pieces for others
                if (key === 'cloth_malmal') {
                    unit = 'میٹر'; // Meter for cloth
                } else if (key === 'surrf') {
                    unit = 'گرام'; // Gram for surrf
                } else if (key === 'wood') {
                    unit = 'کلو'; // Kilo for wood
                }
            }
            
            additionalItemsHTML += `
                <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background: #eff6ff; border-radius: 6px; border-left: 3px solid #3b82f6;">
                    <div>
                        <strong class="text-info">${escapeHtml(displayName)}</strong><br>
                        <small class="text-muted">${reviewTranslations.quantity}: ${quantity} ${unit}</small>
                    </div>
                </div>
            `;
        }
    });
    
    if (hasAdditionalItems) {
        dishesHTML += additionalItemsHTML;
    }
    
    if (dishesHTML) {
        reviewDishes.innerHTML = dishesHTML;
    } else {
        reviewDishes.innerHTML = '<p class="text-muted mb-0">No dishes added</p>';
    }
    
    // Update total amount
    const reviewTotal = document.getElementById('reviewTotal');
    if (reviewTotal) {
        reviewTotal.textContent = 'Rs ' + totalAmount.toFixed(2);
    }
    if (typeof updateAdvanceSummary === 'function') {
        updateAdvanceSummary(totalAmount);
    }
}

function updateAdvanceSummary(forcedTotal) {
    let total = typeof forcedTotal === 'number' ? forcedTotal : null;
    if (total === null) {
        const reviewTotal = document.getElementById('reviewTotal');
        const raw = (reviewTotal && reviewTotal.textContent) ? reviewTotal.textContent.replace(/[^\d.]/g, '') : '0';
        total = parseFloat(raw) || 0;
    }
    const advanceInput = document.getElementById('advance_amount');
    let advance = parseFloat(advanceInput ? advanceInput.value : 0) || 0;
    if (advance < 0) advance = 0;
    if (advance > total && total > 0) {
        advance = total;
        if (advanceInput) advanceInput.value = advance.toFixed(2);
    }
    const remaining = Math.max(0, total - advance);
    const reviewAdvance = document.getElementById('reviewAdvance');
    const reviewRemaining = document.getElementById('reviewRemaining');
    if (reviewAdvance) reviewAdvance.textContent = 'Rs ' + advance.toFixed(2);
    if (reviewRemaining) reviewRemaining.textContent = 'Rs ' + remaining.toFixed(2);
}
window.updateAdvanceSummary = updateAdvanceSummary;

// Escape HTML function (will be defined in the dishes management section)
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Listen for changes to update review in real-time
document.addEventListener('DOMContentLoaded', function() {
    // Auto-fill customer cell when customer name is selected from dropdown
    const customerName = document.getElementById('customer_name');
    const customerCell = document.getElementById('customer_cell');
    const customerDatalist = document.getElementById('customer_names_list');
    
    if (customerName && customerCell && customerDatalist) {
        // Store customer data for quick lookup
        const customerData = {};
        <?php foreach ($all_customer_names as $cust_info): ?>
        customerData['<?php echo addslashes($cust_info['name']); ?>'] = '<?php echo addslashes($cust_info['cell']); ?>';
        <?php endforeach; ?>
        
        // Handle input event to auto-fill cell number
        customerName.addEventListener('input', function() {
            const selectedName = this.value.trim();
            if (customerData[selectedName]) {
                customerCell.value = customerData[selectedName];
            }
        });
        
        // Handle change event (when dropdown option is selected)
        customerName.addEventListener('change', function() {
            const selectedName = this.value.trim();
            if (customerData[selectedName]) {
                customerCell.value = customerData[selectedName];
            }
        });
    }
    
    // Listen for new form field changes
    const customerNameField = document.getElementById('customer_name');
    const customerCellField = document.getElementById('customer_cell');
    const numberOfPersons = document.getElementById('number_of_persons');
    const shift = document.getElementById('shift');
    const deliveryDate = document.getElementById('delivery_date');
    const deliveryTime = document.getElementById('delivery_time');
    
    [customerNameField, customerCellField, numberOfPersons, shift, deliveryDate, deliveryTime].forEach(function(field) {
        if (field) {
            field.addEventListener('change', function() {
                if (currentStep === 4) {
                    updateReview();
                }
            });
        }
    });
    
    // Old form - customer selection
    const customerSelect = document.getElementById('customer_id');
    if (customerSelect) {
        customerSelect.addEventListener('change', function() {
            if (currentStep === 4) {
                updateReview();
            }
        });
    }
    
    // Listen for dish changes
    const dishesContainer = document.getElementById('dishesContainer');
    if (dishesContainer) {
        dishesContainer.addEventListener('input', function(e) {
            if (currentStep === 4 && (e.target.classList.contains('dish-select') || 
                e.target.classList.contains('dish-quantity') || 
                e.target.classList.contains('dish-unit') ||
                e.target.classList.contains('dish-unit-price') || 
                e.target.classList.contains('dish-total-amount'))) {
                updateReview();
            }
        });
        dishesContainer.addEventListener('change', function(e) {
            if (e.target.classList.contains('dish-select')) {
                // Update unit dropdown when dish is selected
                updateUnitDropdown(e.target);
            }
            if (currentStep === 4 && (e.target.classList.contains('dish-select') || 
                e.target.classList.contains('dish-unit'))) {
                updateReview();
            }
        });
    }
    
});

// Multiple dishes management
document.addEventListener('DOMContentLoaded', function() {
    const dishesContainer = document.getElementById('dishesContainer');
    const addDishBtn = document.getElementById('addDishBtn');
    let dishRowCount = 1; // Start from 1 since we already have row 0
    
    // Get dishes data for cloning (reuse global — avoid double json_encode)
    const dishesData = window.dishesData || [];
    
    // Get ingredients data for extra ingredients
    const ingredientsData = <?php echo json_encode($ingredients); ?>;
    window.ingredientsData = ingredientsData; // Make it globally available for review function
    
    // Make review translations globally available
    window.reviewTranslations = {
        extra_ingredients: '<?php echo addslashes(t("extra_ingredients")); ?>',
        additional_items: '<?php echo addslashes(t("additional_items")); ?>',
        quantity: '<?php echo addslashes(t("quantity")); ?>',
        ingredient_name: '<?php echo addslashes(t("ingredient_name")); ?>',
        unit_label: '<?php echo addslashes(t("unit_label")); ?>',
        delete: '<?php echo addslashes(t("delete")); ?>',
        select_ingredient: '<?php echo addslashes(t("select_ingredient")); ?>',
        add: '<?php echo addslashes(t("add")); ?>',
        cloth_malmal: '<?php echo addslashes(t("cloth_malmal")); ?>',
        match_box: '<?php echo addslashes(t("match_box")); ?>',
        surrf: '<?php echo addslashes(t("surrf")); ?>',
        wood: '<?php echo addslashes(t("wood", "لکڑی")); ?>',
        sponjis_iron: '<?php echo addslashes(t("sponjis_iron")); ?>',
        sobi_iron: '<?php echo addslashes(t("sobi_iron")); ?>',
        steam_pot_with_lid: '<?php echo addslashes(t("steam_pot_with_lid")); ?>',
        deg: '<?php echo addslashes(t("deg")); ?>',
        karahi: '<?php echo addslashes(t("karahi")); ?>',
        chulhe: '<?php echo addslashes(t("chulhe")); ?>',
        parat: '<?php echo addslashes(t("parat")); ?>',
        tub: '<?php echo addslashes(t("tub")); ?>',
        shamiana: '<?php echo addslashes(t("shamiana")); ?>',
        qanat: '<?php echo addslashes(t("qanat")); ?>',
        dari: '<?php echo addslashes(t("dari")); ?>',
        charpai: '<?php echo addslashes(t("charpai")); ?>',
        coal: '<?php echo addslashes(t("coal")); ?>',
        steam_pot_without_lid: '<?php echo addslashes(t("steam_pot_without_lid")); ?>',
        unit_placeholder: '<?php echo addslashes(t("unit_placeholder", "kg, g, pieces, etc.")); ?>'
    };
    
    // Function to create dish options HTML
    function getDishOptionsHTML() {
        let html = '<option value=""><?php e('select_dish'); ?></option>';
        dishesData.forEach(function(dish) {
            const img = dish.image_url ? String(dish.image_url).replace(/"/g, '&quot;') : '';
            html += '<option value="' + dish.id + '" data-image="' + img + '">' + escapeHtml(dish.name) + '</option>';
        });
        return html;
    }
    
    // Function to create ingredient options HTML
    function getIngredientOptionsHTML() {
        let html = '<option value="">Select Ingredient</option>';
        ingredientsData.forEach(function(ingredient) {
            const unitText = ingredient.unit ? ' (' + escapeHtml(ingredient.unit) + ')' : '';
            html += '<option value="' + ingredient.id + '">' + escapeHtml(ingredient.name) + unitText + '</option>';
        });
        return html;
    }
    
    // Function to escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
    
    // Function to setup row event listeners
    function setupRowListeners(row) {
        const quantityInput = row.querySelector('.dish-quantity');
        const unitPriceInput = row.querySelector('.dish-unit-price');
        const totalAmountInput = row.querySelector('.dish-total-amount');
        let isManualTotalEdit = false;
        let lastCalculatedTotal = 0;
        
        function calculateTotal() {
            if (isManualTotalEdit) {
                return;
            }
            
            const quantity = parseFloat(quantityInput.value) || 0;
            const unitPrice = parseFloat(unitPriceInput.value) || 0;
            
            if (unitPrice > 0 && quantity > 0) {
                const calculatedTotal = quantity * unitPrice;
                lastCalculatedTotal = calculatedTotal;
                totalAmountInput.value = calculatedTotal.toFixed(2);
            } else if (unitPrice === 0 || !unitPriceInput.value) {
                if (!isManualTotalEdit) {
                    totalAmountInput.value = '';
                }
            }
            
            // Update review if on step 4
            if (currentStep === 4) {
                updateReview();
            }
        }
        
        quantityInput.addEventListener('input', calculateTotal);
        unitPriceInput.addEventListener('input', calculateTotal);
        
        totalAmountInput.addEventListener('focus', function() {
            isManualTotalEdit = false;
        });
        
        totalAmountInput.addEventListener('input', function() {
            const currentValue = parseFloat(this.value) || 0;
            if (Math.abs(currentValue - lastCalculatedTotal) > 0.01) {
                isManualTotalEdit = true;
            }
            
            // Update review if on step 4
            if (currentStep === 4) {
                updateReview();
            }
        });
        
        totalAmountInput.addEventListener('blur', function() {
            if (!this.value || this.value === '') {
                isManualTotalEdit = false;
                calculateTotal();
            } else if (currentStep === 4) {
                updateReview();
            }
        });
    }
    
    // Function to update unit dropdown based on selected dish
    function updateUnitDropdown(dishSelect) {
        const row = dishSelect.closest('.dish-row');
        if (!row) return;
        
        const unitSelect = row.querySelector('.dish-unit');
        if (!unitSelect) return;
        
        const dishId = dishSelect.value;
        const dish = dishesData.find(d => d.id == dishId);
        
        // Clear existing options
        unitSelect.innerHTML = '<option value=""><?php echo t('select_unit', 'Select Unit'); ?></option>';
        
        // Always show these 4 units in the specified order
        const defaultUnits = ['دیگ', 'لیٹر', 'عدد', 'کلو'];
        defaultUnits.forEach(unit => {
            const option = document.createElement('option');
            option.value = unit;
            option.textContent = unit;
            // If dish has base_unit and it matches one of these units, select it
            if (dish && dish.base_unit && dish.base_unit === unit) {
                option.selected = true;
            }
            unitSelect.appendChild(option);
        });
        
        // Make unit required when dish is selected
        if (dishId) {
            unitSelect.setAttribute('required', 'required');
        } else {
            unitSelect.removeAttribute('required');
        }

        if (typeof renderDishIngredientsPanel === 'function') {
            renderDishIngredientsPanel(row);
        }
        if (typeof updateDishThumb === 'function') {
            updateDishThumb(row);
        }
    }
    
    // Make updateUnitDropdown available globally
    window.updateUnitDropdown = updateUnitDropdown;
    
    // Initialize unit dropdowns for all existing rows on page load
    function initializeUnitDropdowns() {
        document.querySelectorAll('.dish-row').forEach(function(row) {
            const unitSelect = row.querySelector('.dish-unit');
            if (unitSelect) {
                // Always ensure dropdown has options
                if (unitSelect.children.length <= 1) {
                    unitSelect.innerHTML = '<option value=""><?php echo t('select_unit', 'Select Unit'); ?></option>';
                    const defaultUnits = ['دیگ', 'لیٹر', 'عدد', 'کلو'];
                    defaultUnits.forEach(unit => {
                        const option = document.createElement('option');
                        option.value = unit;
                        option.textContent = unit;
                        unitSelect.appendChild(option);
                    });
                }
            }
        });
    }
    
    // Make initializeUnitDropdowns available globally
    window.initializeUnitDropdowns = initializeUnitDropdowns;
    
    // Initialize on page load
    initializeUnitDropdowns();
    
    // Setup listeners for existing rows
    document.querySelectorAll('.dish-row').forEach(function(row) {
        setupRowListeners(row);
        // Add event listener for dish selection to update unit
        const dishSelect = row.querySelector('.dish-select');
        if (dishSelect) {
            // Initialize unit dropdown if dish is already selected
            if (dishSelect.value) {
                updateUnitDropdown(dishSelect);
            }
            // Add change listener
            dishSelect.addEventListener('change', function() {
                updateUnitDropdown(this);
            });
        }
    });
    
    // Add new dish row
    addDishBtn.addEventListener('click', function() {
        const newRow = document.createElement('div');
        newRow.className = 'dish-row mb-3 p-3 border rounded';
        newRow.setAttribute('data-row', dishRowCount);
        
        newRow.innerHTML = `
            <div class="row g-3 align-items-end">
                <div class="col-auto">
                    <div class="dish-thumb-wrap" style="width:72px;height:72px;border-radius:12px;overflow:hidden;background:#e2e8f0;border:1px solid #cbd5e1;">
                        <div class="dish-thumb-placeholder w-100 h-100 d-flex align-items-center justify-content-center" style="background:linear-gradient(135deg,#667eea,#764ba2);">
                            <i class="bi bi-egg-fried text-white fs-3"></i>
                        </div>
                        <img class="dish-thumb-img w-100 h-100" alt="" style="object-fit:cover;display:none;" loading="lazy" decoding="async"
                             onerror="this.style.display='none';this.previousElementSibling.style.display='flex';">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">
                        <?php e('dish'); ?> <span class="text-danger">*</span>
                    </label>
                    <div class="position-relative">
                        <select class="form-select dish-select" name="dishes[${dishRowCount}][dish_id]" required onfocus="openDishSelectionModal(${dishRowCount})">
                            ${getDishOptionsHTML()}
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-primary position-absolute end-0 top-0 h-100" 
                                style="border-top-left-radius: 0; border-bottom-left-radius: 0; z-index: 10;"
                                onclick="openDishSelectionModal(${dishRowCount})" title="Browse dishes with pictures">
                            <i class="bi bi-grid-3x3-gap"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-semibold small">
                        <?php echo t('unit', 'Unit'); ?> <span class="text-danger">*</span>
                    </label>
                    <select class="form-select dish-unit" name="dishes[${dishRowCount}][unit]" required>
                        <option value=""><?php echo t('select_unit', 'Select Unit'); ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">
                        <?php e('quantity'); ?> <span class="text-danger">*</span>
                    </label>
                    <input type="number" class="form-control dish-quantity" name="dishes[${dishRowCount}][quantity]" 
                           placeholder="1" step="0.01" min="0.01" value="1" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">
                        <?php e('unit_price'); ?> (Rs)
                    </label>
                    <input type="number" class="form-control dish-unit-price" name="dishes[${dishRowCount}][unit_price]" 
                           placeholder="0.00" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">
                        <?php e('total_amount'); ?> (Rs)
                    </label>
                    <input type="number" class="form-control dish-total-amount" name="dishes[${dishRowCount}][total_amount]" 
                           placeholder="0.00" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small d-block">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger remove-dish-btn">
                        <i class="bi bi-trash"></i> Remove
                    </button>
                </div>
            </div>
            <div class="dish-ingredients-panel mt-3 pt-2 border-top" style="display: none;">
                <div class="small fw-semibold text-muted mb-2">
                    <i class="bi bi-dash-circle me-1"></i>
                    Dish ingredients — remove jo is order mein nahi chahiye
                </div>
                <div class="dish-ingredients-list d-flex flex-wrap gap-2"></div>
            </div>
        `;
        
        dishesContainer.appendChild(newRow);
        setupRowListeners(newRow);
        
        // Initialize unit dropdown with default options for new row
        const unitSelect = newRow.querySelector('.dish-unit');
        if (unitSelect) {
            unitSelect.innerHTML = '<option value=""><?php echo t('select_unit', 'Select Unit'); ?></option>';
            const defaultUnits = ['دیگ', 'لیٹر', 'عدد', 'کلو'];
            defaultUnits.forEach(unit => {
                const option = document.createElement('option');
                option.value = unit;
                option.textContent = unit;
                unitSelect.appendChild(option);
            });
        }
        
        // Add event listener for dish selection to update unit
        const dishSelect = newRow.querySelector('.dish-select');
        if (dishSelect) {
            dishSelect.addEventListener('change', function() {
                updateUnitDropdown(this);
            });
        }
        
        updateRemoveButtons();
        dishRowCount++;
        
        // Update review if on step 4
        if (currentStep === 4) {
            updateReview();
        }
    });
    
    // Remove dish row
    dishesContainer.addEventListener('click', function(e) {
        if (e.target.closest('.remove-dish-btn')) {
            const row = e.target.closest('.dish-row');
            if (document.querySelectorAll('.dish-row').length > 1) {
                row.remove();
                updateRemoveButtons();
                
                // Update review if on step 4
                if (currentStep === 4) {
                    updateReview();
                }
            }
        }
    });
    
    // Update remove buttons visibility
    function updateRemoveButtons() {
        const rows = document.querySelectorAll('.dish-row');
        rows.forEach(function(row) {
            const removeBtn = row.querySelector('.remove-dish-btn');
            if (rows.length > 1) {
                removeBtn.style.display = 'block';
            } else {
                removeBtn.style.display = 'none';
            }
        });
    }
    
    // Initial setup
    updateRemoveButtons();
    
    // Extra Ingredients Management
    const extraIngredientsContainer = document.getElementById('extraIngredientsContainer');
    const addExtraIngredientBtn = document.getElementById('addExtraIngredientBtn');
    let extraIngredientRowCount = 0;
    
    // Add extra ingredient row
    if (addExtraIngredientBtn && extraIngredientsContainer) {
        addExtraIngredientBtn.addEventListener('click', function() {
            const newRow = document.createElement('div');
            newRow.className = 'extra-ingredient-row mb-3 p-3 border rounded';
            newRow.setAttribute('data-row', extraIngredientRowCount);
            newRow.style.display = 'block';
            
            const reviewTranslations = window.reviewTranslations || {};
            newRow.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">
                            ${reviewTranslations.ingredient_name || 'Ingredient'} <span class="text-danger">*</span>
                        </label>
                        <select class="form-select extra-ingredient-select" name="extra_ingredients[${extraIngredientRowCount}][ingredient_id]">
                            ${getIngredientOptionsHTML()}
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">
                            ${reviewTranslations.quantity || 'Quantity'} <span class="text-danger">*</span>
                        </label>
                        <input type="number" class="form-control extra-ingredient-quantity" 
                               name="extra_ingredients[${extraIngredientRowCount}][quantity]" 
                               placeholder="0.00" step="0.01" min="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">
                            ${reviewTranslations.unit_label || 'Unit'}
                        </label>
                        <select class="form-select extra-ingredient-unit" name="extra_ingredients[${extraIngredientRowCount}][unit]">
                            <option value="">${reviewTranslations.select_unit || 'Select Unit'}</option>
                            <option value="کلو">کلو</option>
                            <option value="گرام">گرام</option>
                            <option value="عدد">عدد</option>
                            <option value="گچھی">گچھی</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small d-block">&nbsp;</label>
                        <button type="button" class="btn btn-sm btn-danger remove-extra-ingredient-btn">
                            <i class="bi bi-trash"></i> ${reviewTranslations.delete || 'Remove'}
                        </button>
                    </div>
                </div>
            `;
            
            extraIngredientsContainer.appendChild(newRow);
            
            // Auto-fill unit when ingredient is selected (if unit matches one of the dropdown options)
            const select = newRow.querySelector('.extra-ingredient-select');
            const unitSelect = newRow.querySelector('.extra-ingredient-unit');
            if (select && unitSelect) {
                select.addEventListener('change', function() {
                    const ingredientId = this.value;
                    const ingredient = ingredientsData.find(i => i.id == ingredientId);
                    if (ingredient && ingredient.unit) {
                        // Check if the ingredient unit matches one of the dropdown options
                        const validUnits = ['کلو', 'گرام', 'عدد', 'گچھی'];
                        if (validUnits.includes(ingredient.unit)) {
                            unitSelect.value = ingredient.unit;
                        }
                    }
                });
            }
            
            extraIngredientRowCount++;
        });
        
        // Remove extra ingredient row
        extraIngredientsContainer.addEventListener('click', function(e) {
            if (e.target.closest('.remove-extra-ingredient-btn')) {
                const row = e.target.closest('.extra-ingredient-row');
                if (row) {
                    row.remove();
                }
            }
        });
        
        // Auto-fill unit for existing rows
        document.querySelectorAll('.extra-ingredient-select').forEach(function(select) {
            const row = select.closest('.extra-ingredient-row');
            const unitSelect = row ? row.querySelector('.extra-ingredient-unit') : null;
            if (select && unitSelect) {
                select.addEventListener('change', function() {
                    const ingredientId = this.value;
                    const ingredient = ingredientsData.find(i => i.id == ingredientId);
                    if (ingredient && ingredient.unit) {
                        // Check if the ingredient unit matches one of the dropdown options
                        const validUnits = ['کلو', 'گرام', 'عدد', 'گچھی'];
                        if (validUnits.includes(ingredient.unit)) {
                            unitSelect.value = ingredient.unit;
                        }
                    }
                });
            }
        });
    }
    
    // Search functionality for orders
    const searchInput = document.getElementById('searchOrders');
    const clearSearchBtn = document.getElementById('clearSearchOrders');
    const ordersList = document.getElementById('ordersList');
    const noOrdersResults = document.getElementById('noOrdersResults');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const orderItems = document.querySelectorAll('.order-item');
            let visibleCount = 0;
            
            if (searchTerm === '') {
                // Show all orders and date headers
                orderItems.forEach(item => {
                    item.style.display = '';
                    visibleCount++;
                });
                const dateHeaders = document.querySelectorAll('.date-header');
                dateHeaders.forEach(header => {
                    header.style.display = '';
                });
                clearSearchBtn.style.display = 'none';
                if (noOrdersResults) noOrdersResults.style.display = 'none';
                if (ordersList) ordersList.style.display = '';
            } else {
                // Filter orders - also hide/show date headers
                const dateHeaders = document.querySelectorAll('.date-header');
                let hasVisibleOrdersForDate = {};
                
                orderItems.forEach(item => {
                    const orderNumber = item.getAttribute('data-order-number') || '';
                    const orderId = item.getAttribute('data-id') || '';
                    const customerName = item.getAttribute('data-customer') || '';
                    const phoneNumber = item.getAttribute('data-phone') || '';
                    
                    // Get the date header for this order item
                    let dateHeader = null;
                    let currentElement = item.previousElementSibling;
                    while (currentElement) {
                        if (currentElement.classList && currentElement.classList.contains('date-header')) {
                            dateHeader = currentElement;
                            break;
                        }
                        currentElement = currentElement.previousElementSibling;
                    }
                    
                    // Search in order number, customer name, phone number, or order ID
                    if (orderNumber.toLowerCase().includes(searchTerm) || 
                        orderId.includes(searchTerm) || 
                        customerName.includes(searchTerm) ||
                        phoneNumber.includes(searchTerm)) {
                        item.style.display = '';
                        visibleCount++;
                        // Mark that this date has visible orders
                        if (dateHeader) {
                            const dateKey = dateHeader.textContent.trim();
                            hasVisibleOrdersForDate[dateKey] = true;
                        }
                    } else {
                        item.style.display = 'none';
                    }
                });
                
                // Hide date headers that have no visible orders
                dateHeaders.forEach(header => {
                    const dateKey = header.textContent.trim();
                    if (!hasVisibleOrdersForDate[dateKey]) {
                        header.style.display = 'none';
                    } else {
                        header.style.display = '';
                    }
                });
                
                clearSearchBtn.style.display = searchTerm ? 'block' : 'none';
                
                // Show/hide no results message
                if (visibleCount === 0) {
                    if (noOrdersResults) noOrdersResults.style.display = 'block';
                    if (ordersList) ordersList.style.display = 'none';
                } else {
                    if (noOrdersResults) noOrdersResults.style.display = 'none';
                    if (ordersList) ordersList.style.display = '';
                }
            }
        });
        
        // Clear search
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.focus();
            });
        }
    }
});

// Get translations from PHP - Force Urdu for print
const translations = <?php 
try {
    // Force Urdu language for print translations
    $originalLang = getCurrentLanguage();
    $_SESSION['lang'] = 'ur'; // Temporarily set to Urdu
    
    // Load Urdu translations directly
    $urduTranslations = include __DIR__ . '/../translations/ur.php';
    
    echo json_encode([
        'brand_name' => $urduTranslations['brand_name'] ?? 'حسن کک',
        'ingredients_list' => $urduTranslations['ingredients_list'] ?? 'آرڈر کے لیے اجزاء کی فہرست',
        'order_id' => $urduTranslations['order_id'] ?? 'آرڈر نمبر',
        'dish' => $urduTranslations['dish'] ?? 'کھانا',
        'quantity' => $urduTranslations['quantity'] ?? 'مقدار',
        'order_date' => $urduTranslations['order_date'] ?? 'آرڈر کی تاریخ',
        'printed_on' => $urduTranslations['printed_on'] ?? 'پرنٹ کی تاریخ',
        'print' => $urduTranslations['print'] ?? 'پرنٹ کریں',
        'close' => $urduTranslations['close'] ?? 'بند کریں',
        'ingredient_label' => $urduTranslations['ingredient_label'] ?? 'جزو',
        'quantity_label' => $urduTranslations['quantity_label'] ?? 'مقدار',
        'unit_label' => $urduTranslations['unit_label'] ?? 'اکائی',
        'no_ingredients_found' => $urduTranslations['no_ingredients_found'] ?? 'اس کھانے کے لیے کوئی جزو نہیں ملا۔',
        'order_receipt' => $urduTranslations['order_receipt'] ?? 'آرڈر کی رسید',
        'order_details' => $urduTranslations['order_details'] ?? 'آرڈر کی تفصیلات',
        'customer' => $urduTranslations['customer'] ?? 'گاہک',
        'email' => $urduTranslations['email'] ?? 'ای میل',
        'unit_price' => $urduTranslations['unit_price'] ?? 'یونٹ قیمت',
        'total_amount' => $urduTranslations['total_amount'] ?? 'کل رقم',
        'notes' => $urduTranslations['notes'] ?? 'نوٹس',
        'thank_you' => $urduTranslations['thank_you'] ?? 'آپ کے آرڈر کا شکریہ!',
        'status' => $urduTranslations['status'] ?? 'حالت',
        'number_of_persons' => $urduTranslations['number_of_persons'] ?? 'افراد کی تعداد',
        'persons' => $urduTranslations['persons'] ?? 'افراد',
        'extra_ingredients' => $urduTranslations['extra_ingredients'] ?? 'اضافی اجزاء',
        'additional_items' => $urduTranslations['additional_items'] ?? 'اضافی اشیاء',
        'cloth_malmal' => $urduTranslations['cloth_malmal'] ?? 'کپڑا ململ',
        'match_box' => $urduTranslations['match_box'] ?? 'ماچس',
        'surrf' => $urduTranslations['surrf'] ?? 'سرف',
        'wood' => $urduTranslations['wood'] ?? 'لکڑی',
        'sponjis_iron' => $urduTranslations['sponjis_iron'] ?? 'اسپنجز (آئرن)',
        'sobi_iron' => $urduTranslations['sobi_iron'] ?? 'صوبی(لوہے والی )',
        'steam_pot_with_lid' => $urduTranslations['steam_pot_with_lid'] ?? 'سٹیم پتیلہ جال ڈھکن',
        'deg' => $urduTranslations['deg'] ?? 'دیگ',
        'karahi' => $urduTranslations['karahi'] ?? 'کڑاہی',
        'chulhe' => $urduTranslations['chulhe'] ?? 'چولہے',
        'parat' => $urduTranslations['parat'] ?? 'پرات',
        'tub' => $urduTranslations['tub'] ?? 'ٹب',
        'shamiana' => $urduTranslations['shamiana'] ?? 'شامیانہ',
        'qanat' => $urduTranslations['qanat'] ?? 'قنات',
        'dari' => $urduTranslations['dari'] ?? 'دری',
        'charpai' => $urduTranslations['charpai'] ?? 'چارپائی',
        'coal' => $urduTranslations['coal'] ?? 'کوئلہ',
        'steam_pot_without_lid' => $urduTranslations['steam_pot_without_lid'] ?? 'سٹیم پتیلہ بغیر ڈھکن',
        'pieces' => $urduTranslations['pieces'] ?? 'عدد'
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    
    // Restore original language
    $_SESSION['lang'] = $originalLang;
} catch (Exception $e) {
    // Fallback to hardcoded Urdu translations
    echo json_encode([
        'brand_name' => 'حسن کک',
        'ingredients_list' => 'آرڈر کے لیے اجزاء کی فہرست',
        'order_id' => 'آرڈر نمبر',
        'dish' => 'کھانا',
        'quantity' => 'مقدار',
        'order_date' => 'آرڈر کی تاریخ',
        'printed_on' => 'پرنٹ کی تاریخ',
        'print' => 'پرنٹ کریں',
        'close' => 'بند کریں',
        'ingredient_label' => 'جزو',
        'quantity_label' => 'مقدار',
        'unit_label' => 'اکائی',
        'no_ingredients_found' => 'اس کھانے کے لیے کوئی جزو نہیں ملا۔',
        'order_receipt' => 'آرڈر کی رسید',
        'order_details' => 'آرڈر کی تفصیلات',
        'customer' => 'گاہک',
        'email' => 'ای میل',
        'unit_price' => 'یونٹ قیمت',
        'total_amount' => 'کل رقم',
        'notes' => 'نوٹس',
        'thank_you' => 'آپ کے آرڈر کا شکریہ!',
        'status' => 'حالت',
        'number_of_persons' => 'افراد کی تعداد',
        'persons' => 'افراد',
        'extra_ingredients' => 'اضافی اجزاء',
        'additional_items' => 'اضافی اشیاء',
        'cloth_malmal' => 'کپڑا ململ',
        'match_box' => 'ماچس',
        'surrf' => 'سرف',
        'wood' => 'لکڑی',
        'sponjis_iron' => 'اسپنجز (آئرن)',
        'sobi_iron' => 'صوبی(لوہے والی )',
        'steam_pot_with_lid' => 'سٹیم پتیلہ جال ڈھکن',
        'deg' => 'دیگ',
        'karahi' => 'کڑاہی',
        'chulhe' => 'چولہے',
        'parat' => 'پرات',
        'tub' => 'ٹب',
        'shamiana' => 'شامیانہ',
        'qanat' => 'قنات',
        'dari' => 'دری',
        'charpai' => 'چارپائی',
        'coal' => 'کوئلہ',
        'steam_pot_without_lid' => 'سٹیم پتیلہ بغیر ڈھکن',
        'pieces' => 'عدد'
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
?>;
const currentLang = '<?php echo addslashes(getCurrentLanguage()); ?>';
const langDir = '<?php echo addslashes(getLanguageDirection()); ?>';
const ordersData = <?php 
try {
    // Clean grouped orders data for JSON encoding
    $cleanOrders = [];
    foreach ($all_grouped_orders as $grouped_order) {
        // Decode extra_ingredients JSON string if it exists
        $extra_ingredients_data = null;
        if (!empty($grouped_order['extra_ingredients'])) {
            $decoded = json_decode($grouped_order['extra_ingredients'], true);
            if ($decoded !== null) {
                $extra_ingredients_data = $decoded;
            } else {
                // If decode fails, keep as string and let JavaScript handle it
                $extra_ingredients_data = $grouped_order['extra_ingredients'];
            }
        }
        
        $orderData = [
            'order_number' => $grouped_order['order_number'] ?? '',
            'id' => $grouped_order['id'] ?? 0,
            'customer_name' => $grouped_order['customer_name'] ?? '',
            'customer_email' => $grouped_order['customer_email'] ?? '',
            'customer_cell' => $grouped_order['customer_cell'] ?? '',
            'order_date' => $grouped_order['order_date'] ?? '',
            'delivery_date' => $grouped_order['delivery_date'] ?? '',
            'delivery_time' => $grouped_order['delivery_time'] ?? '',
            'shift' => $grouped_order['shift'] ?? '',
            'number_of_persons' => $grouped_order['number_of_persons'] ?? 0,
            'status' => $grouped_order['status'] ?? 'pending',
            'total_amount' => $grouped_order['total_amount'] ?? 0,
            'advance_amount' => $grouped_order['advance_amount'] ?? 0,
            'remaining_amount' => $grouped_order['remaining_amount'] ?? 0,
            'notes' => $grouped_order['notes'] ?? '',
            'extra_ingredients' => $extra_ingredients_data,
            'dishes' => []
        ];
        
        foreach ($grouped_order['dishes'] as $dish) {
            $orderData['dishes'][] = [
                'dish_name' => $dish['dish_name'] ?? '',
                'dish_id' => $dish['dish_id'] ?? 0,
                'quantity' => $dish['quantity'] ?? 0,
                'unit' => $dish['unit'] ?? '',
                'total_amount' => $dish['total_amount'] ?? 0,
                'number_of_persons' => $orderData['number_of_persons'] ?? ($dish['number_of_persons'] ?? 1),
                'category_name' => $dish['dish_category_name'] ?? 'Uncategorized',
                'ingredients' => $dish['ingredients'] ?? [],
                'removed_ingredient_ids' => $dish['removed_ingredient_ids'] ?? [],
            ];
        }
        $cleanOrders[] = $orderData;
    }
    echo json_encode($cleanOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Exception $e) {
    echo '[]';
}
?>;

// Print Ingredients Function
function printIngredients(orderNumberOrId) {
    if (!ordersData || ordersData.length === 0) {
        alert('No orders data available.');
        return;
    }
    // Find by order_number or id
    const order = ordersData.find(o => o.order_number == orderNumberOrId || o.id == orderNumberOrId);
    if (!order) {
        alert('Order not found.');
        return;
    }
    
    // Get the base path for the image from PHP
    const logoPath = '<?php echo htmlspecialchars($logoPath, ENT_QUOTES); ?>';
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    const cakeImagePath = basePath + 'images/cake.png';
    // Use relative path - base tag in print window will handle it
    const bannerImagePath = 'images/newimage.png';
    
    // Get number of persons from order level
    const totalPersons = parseInt(order.number_of_persons) || 0;
    
    // Shift translation mapping
    const shiftTranslations = {
        'morning': 'صبح',
        'afternoon': 'دوپہر',
        'evening': 'شام',
        '': ''
    };
    
    // Unit conversion helper functions
    // Check if a unit is a weight unit (can be converted to grams)
    function isWeightUnit(unit) {
        if (!unit) return false;
        const unitLower = unit.toLowerCase().trim();
        const weightUnits = ['kg', 'kilogram', 'kilograms', 'کلو', 'g', 'gram', 'grams', 'گرام', 'mg', 'milligram', 'milligrams', 'oz', 'ounce', 'lb', 'pound'];
        return weightUnits.includes(unitLower) || unit === 'کلو' || unit === 'گرام';
    }
    
    // Check if units are compatible (same type)
    function areUnitsCompatible(unit1, unit2) {
        if (!unit1 || !unit2) return false;
        const u1 = unit1.toLowerCase().trim();
        const u2 = unit2.toLowerCase().trim();
        
        // If both are weight units, they're compatible
        if (isWeightUnit(unit1) && isWeightUnit(unit2)) return true;
        
        // If both are volume units, they're compatible
        const volumeUnits = ['liter', 'litre', 'liters', 'litres', 'l', 'ml', 'milliliter', 'milliliters', 'cup', 'tbsp', 'tablespoon', 'tsp', 'teaspoon', 'oz_fluid', 'fl oz'];
        const isVol1 = volumeUnits.includes(u1);
        const isVol2 = volumeUnits.includes(u2);
        if (isVol1 && isVol2) return true;
        
        // If both are count units, they're compatible
        const countUnits = ['piece', 'pieces', 'عدد'];
        const isCount1 = countUnits.includes(u1);
        const isCount2 = countUnits.includes(u2);
        if (isCount1 && isCount2) return true;
        
        // If units are exactly the same, they're compatible
        if (u1 === u2) return true;
        
        return false;
    }
    
    function convertToGrams(quantity, unit) {
        if (!quantity || isNaN(quantity)) return 0;
        if (!isWeightUnit(unit)) {
            // For non-weight units, return null to indicate cannot convert
            return null;
        }
        
        const unitLower = (unit || '').toLowerCase().trim();
        const qty = parseFloat(quantity);
        
        // Convert to grams (base unit)
        if (unitLower === 'kg' || unitLower === 'kilogram' || unitLower === 'kilograms' || unit === 'کلو') {
            return qty * 1000; // kg to grams
        } else if (unitLower === 'g' || unitLower === 'gram' || unitLower === 'grams' || unit === 'گرام') {
            return qty; // already in grams
        } else if (unitLower === 'mg' || unitLower === 'milligram' || unitLower === 'milligrams') {
            return qty / 1000; // mg to grams
        } else if (unitLower === 'oz' || unitLower === 'ounce') {
            return qty * 28.3495; // oz to grams
        } else if (unitLower === 'lb' || unitLower === 'pound') {
            return qty * 453.592; // lb to grams
        }
        // For other weight units, assume grams
        return qty;
    }
    
    function convertFromGrams(grams, preferredUnit) {
        if (grams === null || grams === undefined) {
            // Cannot convert non-weight units
            return null;
        }
        if (!grams || isNaN(grams) || grams === 0) return { quantity: 0, unit: preferredUnit || 'g' };
        
        // If preferred unit is not a weight unit, preserve it as-is
        if (!isWeightUnit(preferredUnit)) {
            return { quantity: grams, unit: preferredUnit || 'g' };
        }
        
        const prefUnitLower = (preferredUnit || '').toLowerCase().trim();
        
        // Preserve the preferred unit if it's kg/kilogram/کلو
        if (prefUnitLower === 'kg' || prefUnitLower === 'kilogram' || prefUnitLower === 'kilograms' || preferredUnit === 'کلو') {
            return { quantity: grams / 1000, unit: 'kg' };
        }
        
        // If preferred unit is g/gram/grams/گرام, keep it in grams
        if (prefUnitLower === 'g' || prefUnitLower === 'gram' || prefUnitLower === 'grams' || preferredUnit === 'گرام') {
            return { quantity: grams, unit: 'g' };
        }
        
        // If preferred unit is oz/ounce
        if (prefUnitLower === 'oz' || prefUnitLower === 'ounce') {
            return { quantity: grams / 28.3495, unit: 'oz' };
        }
        
        // If preferred unit is lb/pound
        if (prefUnitLower === 'lb' || prefUnitLower === 'pound') {
            return { quantity: grams / 453.592, unit: 'lb' };
        }
        
        // If no preferred unit specified, use best fit: kg if >= 1000g, otherwise grams
        if (grams >= 1000) {
            return { quantity: grams / 1000, unit: 'kg' };
        }
        // Otherwise use grams
        return { quantity: grams, unit: 'g' };
    }
    
    // Collect all ingredients from all dishes in the order, grouped by dish name first, then by category
    // This ensures ingredients are completely shown and combined properly by dish name
    let ingredientsByDish = {};
    
    order.dishes.forEach(function(dish) {
        const dishName = dish.dish_name || 'Unknown Dish';
        const dishId = dish.dish_id || 0;
        const orderQuantity = parseFloat(dish.quantity) || 0;
        const dishUnit = dish.unit || ''; // Get dish unit from order
        const ingredients = dish.ingredients || [];
        
        // Use dish name as key to combine same dishes together
        // If same dish appears multiple times, combine all ingredients
        const dishKey = dishName.toLowerCase().trim();
        
        // Initialize dish if not exists
        if (!ingredientsByDish[dishKey]) {
            ingredientsByDish[dishKey] = {
                dish_name: dishName,
                dish_id: dishId,
                quantity: 0, // Will accumulate total quantity
                unit: dishUnit, // Store dish unit
                categories: {}
            };
        }
        
        // Accumulate dish quantity (in case same dish appears multiple times)
        ingredientsByDish[dishKey].quantity += orderQuantity;
        
        // Update unit if not set or if different (prefer non-empty unit)
        if (dishUnit && (!ingredientsByDish[dishKey].unit || ingredientsByDish[dishKey].unit === '')) {
            ingredientsByDish[dishKey].unit = dishUnit;
        }
        
        // Process all ingredients for this dish
        ingredients.forEach(function(ing) {
            // Use ingredient ID and name as key to properly combine duplicates
            const ingredientId = ing.ingredient_id || 0;
            const ingredientName = ing.ingredient_name || 'N/A';
            const key = ingredientId > 0 ? ingredientId : ingredientName.toLowerCase().trim();
            
            // Scale quantity by order quantity
            const scaledQuantity = (parseFloat(ing.quantity) || 0) * orderQuantity;
            const ingUnit = ing.unit || '';
            const categoryName = ing.category_name || 'بغیر زمرہ';
            const categoryId = ing.category_id || 'uncategorized';
            
            // Initialize category if not exists
            if (!ingredientsByDish[dishKey].categories[categoryId]) {
                ingredientsByDish[dishKey].categories[categoryId] = {
                    category_name: categoryName,
                    ingredients: {}
                };
            }
            
            // Add or update ingredient in category - combine quantities if same ingredient appears
            if (ingredientsByDish[dishKey].categories[categoryId].ingredients[key]) {
                const existingIng = ingredientsByDish[dishKey].categories[categoryId].ingredients[key];
                
                // Check if units are compatible for conversion
                if (areUnitsCompatible(existingIng.unit, ingUnit)) {
                    // Units are compatible - convert and combine
                    if (isWeightUnit(existingIng.unit) && isWeightUnit(ingUnit)) {
                        // Both are weight units - convert to grams, add, convert back
                        const existingGrams = convertToGrams(existingIng.quantity, existingIng.unit);
                        const newGrams = convertToGrams(scaledQuantity, ingUnit);
                        const totalGrams = existingGrams + newGrams;
                        
                        // Preserve the original unit from database - use the first one we encountered
                        const preferredUnit = existingIng.originalUnit || ingUnit || 'g';
                        const converted = convertFromGrams(totalGrams, preferredUnit);
                        
                        if (converted) {
                            existingIng.quantity = converted.quantity;
                            existingIng.unit = converted.unit;
                            // Keep the original unit from database (don't change it)
                            if (!existingIng.originalUnit) {
                                existingIng.originalUnit = preferredUnit;
                            }
                        }
                    } else {
                        // Non-weight units - just add quantities if units match exactly
                        if (existingIng.unit === ingUnit || (!existingIng.unit && !ingUnit)) {
                            existingIng.quantity = parseFloat(existingIng.quantity) + parseFloat(scaledQuantity);
                            existingIng.unit = existingIng.unit || ingUnit;
                            existingIng.originalUnit = existingIng.originalUnit || ingUnit;
                        } else {
                            // Units don't match - keep existing, but track separately
                            existingIng.quantity = parseFloat(existingIng.quantity);
                            existingIng.unit = existingIng.unit;
                            existingIng.originalUnit = existingIng.originalUnit || existingIng.unit;
                        }
                    }
                } else {
                    // Units are not compatible - keep existing unit, don't combine
                    existingIng.quantity = parseFloat(existingIng.quantity);
                    existingIng.unit = existingIng.unit;
                    existingIng.originalUnit = existingIng.originalUnit || existingIng.unit;
                }
            } else {
                // Add new ingredient - preserve the original unit from database
                const originalUnit = ingUnit || '';
                
                ingredientsByDish[dishKey].categories[categoryId].ingredients[key] = {
                    ingredient_id: ingredientId,
                    ingredient_name: ingredientName,
                    quantity: scaledQuantity,
                    unit: originalUnit, // Keep original unit from database
                    originalUnit: originalUnit // Store original unit from database
                };
            }
        });
    });
    
    // Process extra ingredients from Step 2
    // Get ingredientsData from the parent window (before opening new window)
    const ingredientsData = typeof window.ingredientsData !== 'undefined' ? window.ingredientsData : [];
    
    if (order.extra_ingredients) {
        try {
            let extraIngredientsData;
            if (typeof order.extra_ingredients === 'string') {
                extraIngredientsData = JSON.parse(order.extra_ingredients);
            } else {
                extraIngredientsData = order.extra_ingredients;
            }
            
            // Process extra_ingredients array
            if (extraIngredientsData && extraIngredientsData.extra_ingredients && Array.isArray(extraIngredientsData.extra_ingredients)) {
                extraIngredientsData.extra_ingredients.forEach(function(extraIng) {
                    const ingredientId = parseInt(extraIng.ingredient_id) || 0;
                    const quantity = parseFloat(extraIng.quantity) || 0;
                    
                    if (ingredientId > 0 && quantity > 0) {
                        // Look up ingredient details from ingredientsData
                        const ingredientInfo = ingredientsData.find(function(i) {
                            return i.id == ingredientId || parseInt(i.id) == ingredientId;
                        });
                        
                        let categoryName, categoryId, ingredientName, unit;
                        
                        if (ingredientInfo) {
                            // Use data from ingredientsData
                            categoryName = ingredientInfo.category_name || 'بغیر زمرہ';
                            categoryId = ingredientInfo.category_id || 'uncategorized';
                            ingredientName = ingredientInfo.name || 'N/A';
                            unit = extraIng.unit || ingredientInfo.unit || '';
                        } else {
                            // Fallback: use data from extra ingredient or default values
                            categoryName = translations.extra_ingredients || 'اضافی اجزاء';
                            categoryId = 'extra_ingredients';
                            ingredientName = 'جزو #' + ingredientId;
                            unit = extraIng.unit || '';
                            
                            if (ingredientsData.length > 0) {
                                console.warn('Ingredient not found in ingredientsData:', ingredientId, 'Available:', ingredientsData.map(i => i.id));
                            }
                        }
                        
                        const key = ingredientId;
                        
                        // Add extra ingredients to a special "extra" dish or create one
                        const extraDishId = 'extra_ingredients_dish';
                        if (!ingredientsByDish[extraDishId]) {
                            ingredientsByDish[extraDishId] = {
                                dish_name: translations.extra_ingredients || 'اضافی اجزاء',
                                dish_id: extraDishId,
                                quantity: 1,
                                categories: {}
                            };
                        }
                        
                        // Initialize category if not exists
                        if (!ingredientsByDish[extraDishId].categories[categoryId]) {
                            ingredientsByDish[extraDishId].categories[categoryId] = {
                                category_name: categoryName,
                                ingredients: {}
                            };
                        }
                        
                        // Add or update ingredient in category - FIXED: Convert units before combining
                        if (ingredientsByDish[extraDishId].categories[categoryId].ingredients[key]) {
                            const existingIng = ingredientsByDish[extraDishId].categories[categoryId].ingredients[key];
                            
                            // Check if units are compatible
                            if (areUnitsCompatible(existingIng.unit, unit)) {
                                if (isWeightUnit(existingIng.unit) && isWeightUnit(unit)) {
                                    // Both are weight units - convert to grams, add, convert back
                                    const existingGrams = convertToGrams(existingIng.quantity, existingIng.unit);
                                    const newGrams = convertToGrams(quantity, unit);
                                    if (existingGrams !== null && newGrams !== null) {
                                        const totalGrams = existingGrams + newGrams;
                                        // Preserve original unit from database
                                        const preferredUnit = existingIng.originalUnit || unit || 'g';
                                        const converted = convertFromGrams(totalGrams, preferredUnit);
                                        
                                        if (converted) {
                                            existingIng.quantity = converted.quantity;
                                            existingIng.unit = converted.unit;
                                            // Keep the original unit from database (don't change it)
                                            if (!existingIng.originalUnit) {
                                                existingIng.originalUnit = preferredUnit;
                                            }
                                        }
                                    }
                                } else {
                                    // Non-weight units - add if units match
                                    if (existingIng.unit === unit || (!existingIng.unit && !unit)) {
                                        existingIng.quantity = parseFloat(existingIng.quantity) + parseFloat(quantity);
                                        existingIng.unit = existingIng.unit || unit;
                                        existingIng.originalUnit = existingIng.originalUnit || unit;
                                    }
                                }
                            }
                        } else {
                            // Add new ingredient - preserve the original unit from database
                            const originalUnit = unit || '';
                            
                            ingredientsByDish[extraDishId].categories[categoryId].ingredients[key] = {
                                ingredient_name: ingredientName,
                                quantity: quantity,
                                unit: originalUnit, // Keep original unit from database
                                originalUnit: originalUnit // Store original unit from database
                            };
                        }
                    }
                });
            }
            
            // Process additional_items object
            if (extraIngredientsData && extraIngredientsData.additional_items && typeof extraIngredientsData.additional_items === 'object') {
                const additionalItemsMap = {
                    'cloth_malmal': translations.cloth_malmal || 'کپڑا ململ',
                    'match_box': translations.match_box || 'ماچس',
                    'surrf': translations.surrf || 'سرف',
                    'wood': translations.wood || 'لکڑی',
                    'sponjis_iron': translations.sponjis_iron || 'اسپنجز (آئرن)',
                    'sobi_iron': translations.sobi_iron || 'صوبی(لوہے والی )',
                    'steam_pot_with_lid': translations.steam_pot_with_lid || 'سٹیم پتیلہ جال ڈھکن',
                    'deg': translations.deg || 'دیگ',
                    'karahi': translations.karahi || 'کڑاہی',
                    'chulhe': translations.chulhe || 'چولہے',
                    'parat': translations.parat || 'پرات',
                    'tub': translations.tub || 'ٹب',
                    'shamiana': translations.shamiana || 'شامیانہ',
                    'qanat': translations.qanat || 'قنات',
                    'dari': translations.dari || 'دری',
                    'charpai': translations.charpai || 'چارپائی',
                    'coal': translations.coal || 'کوئلہ',
                    'steam_pot_without_lid': translations.steam_pot_without_lid || 'سٹیم پتیلہ بغیر ڈھکن'
                };
                
                // Create a special category for additional items
                const additionalItemsCategoryId = 'additional_items';
                const additionalItemsCategoryName = translations.additional_items || 'اضافی اشیاء';
                const additionalItemsDishId = 'additional_items_dish';
                
                if (!ingredientsByDish[additionalItemsDishId]) {
                    ingredientsByDish[additionalItemsDishId] = {
                        dish_name: translations.additional_items || 'اضافی اشیاء',
                        dish_id: additionalItemsDishId,
                        quantity: 1,
                        categories: {}
                    };
                }
                
                // Initialize category if not exists
                if (!ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId]) {
                    ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId] = {
                        category_name: additionalItemsCategoryName,
                        ingredients: {}
                    };
                }
                
                // Process each additional item
                Object.keys(extraIngredientsData.additional_items).forEach(function(itemKey) {
                    const quantity = parseInt(extraIngredientsData.additional_items[itemKey]) || 0;
                    
                    if (quantity > 0) {
                        const itemName = additionalItemsMap[itemKey] || itemKey;
                        const key = 'additional_' + itemKey;
                        
                        // Set unit: meter for cloth_malmal, gram for surrf, kilo for wood, pieces for others
                        let unit = 'عدد'; // Default to pieces
                        if (itemKey === 'cloth_malmal') {
                            unit = 'میٹر'; // Meter for cloth
                        } else if (itemKey === 'surrf') {
                            unit = 'گرام'; // Gram for surrf
                        } else if (itemKey === 'wood') {
                            unit = 'کلو'; // Kilo for wood
                        }
                        
                        // Add or update additional item in category
                        if (ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId].ingredients[key]) {
                            ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId].ingredients[key].quantity += quantity;
                        } else {
                            ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId].ingredients[key] = {
                                ingredient_name: itemName,
                                quantity: quantity,
                                unit: unit
                            };
                        }
                    }
                });
            }
        } catch (e) {
            console.error('Error processing extra ingredients:', e, order.extra_ingredients);
        }
    }
    
    // Force RTL for Urdu
    const textAlign = 'right';
    const textAlignOpposite = 'left';
    const fontFamily = 'Arial, "Noto Sans Arabic", "Segoe UI", Tahoma, sans-serif';
    
    let ingredientsHtml = '<div style="direction: rtl;">';
    
    // Check if we have any ingredients
    const dishKeys = Object.keys(ingredientsByDish);
    if (dishKeys.length === 0) {
        ingredientsHtml += '<table class="ingredients-table" style="width: 100%; border-collapse: collapse; margin-top: 12px; direction: rtl; font-size: 13px;">';
        ingredientsHtml += '<thead><tr style="background-color: #f8fafc;"><th style="padding: 8px 10px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 13px;">' + translations.ingredient_label + '</th><th style="padding: 8px 10px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 13px;">' + translations.quantity_label + ' / ' + translations.unit_label + '</th></tr></thead>';
        ingredientsHtml += '<tbody>';
        ingredientsHtml += '<tr><td colspan="2" style="padding: 8px 10px; border: 1px solid #ddd; text-align: center; font-family: ' + fontFamily + '; font-size: 13px; line-height: 1.5;">' + translations.no_ingredients_found + '</td></tr>';
        ingredientsHtml += '</tbody></table>';
    } else {
        // Collect all ingredients grouped by category from all dishes
        const ingredientsByCategory = {};
        
        // Loop through all dishes and collect ingredients by category
        dishKeys.forEach(function(dishKey) {
            const dish = ingredientsByDish[dishKey];
            const categoryKeys = Object.keys(dish.categories);
            
            categoryKeys.forEach(function(categoryId) {
                const category = dish.categories[categoryId];
                const categoryName = category.category_name || 'بغیر زمرہ';
                const categoryIngredients = Object.values(category.ingredients);
                
                // Initialize category if not exists
                if (!ingredientsByCategory[categoryId]) {
                    ingredientsByCategory[categoryId] = {
                        category_name: categoryName,
                        ingredients: {}
                    };
                }
                
                // Add each ingredient to the category map
                categoryIngredients.forEach(function(ing) {
                    const ingredientId = ing.ingredient_id || 0;
                    const ingredientName = ing.ingredient_name || 'N/A';
                    const key = ingredientId > 0 ? ingredientId : ingredientName.toLowerCase().trim();
                    
                    // Combine ingredients with same name/ID within the category - FIXED: Convert units before adding
                    if (ingredientsByCategory[categoryId].ingredients[key]) {
                        const existingIng = ingredientsByCategory[categoryId].ingredients[key];
                        
                        // Check if units are compatible
                        if (areUnitsCompatible(existingIng.unit, ing.unit || '')) {
                            if (isWeightUnit(existingIng.unit) && isWeightUnit(ing.unit || '')) {
                                // Both are weight units - convert to grams, add, convert back
                                const existingGrams = convertToGrams(existingIng.quantity, existingIng.unit);
                                const newGrams = convertToGrams(parseFloat(ing.quantity) || 0, ing.unit || '');
                                
                                if (existingGrams !== null && newGrams !== null) {
                                    const totalGrams = existingGrams + newGrams;
                                    // Preserve original unit from database
                                    const preferredUnit = existingIng.originalUnit || ing.originalUnit || ing.unit || 'g';
                                    const converted = convertFromGrams(totalGrams, preferredUnit);
                                    
                                    if (converted) {
                                        existingIng.quantity = converted.quantity;
                                        existingIng.unit = converted.unit;
                                        // Keep the original unit from database (don't change it)
                                        if (!existingIng.originalUnit) {
                                            existingIng.originalUnit = preferredUnit;
                                        }
                                    }
                                }
                            } else {
                                // Non-weight units - add if units match
                                if (existingIng.unit === (ing.unit || '') || (!existingIng.unit && !ing.unit)) {
                                    existingIng.quantity = parseFloat(existingIng.quantity) + parseFloat(ing.quantity || 0);
                                    existingIng.unit = existingIng.unit || ing.unit || '';
                                    existingIng.originalUnit = existingIng.originalUnit || existingIng.unit || ing.originalUnit || ing.unit || '';
                                }
                            }
                        }
                    } else {
                        // Add new ingredient - preserve original unit
                        const originalUnit = ing.originalUnit || ing.unit || '';
                        ingredientsByCategory[categoryId].ingredients[key] = {
                            ingredient_id: ingredientId,
                            ingredient_name: ingredientName,
                            quantity: parseFloat(ing.quantity) || 0,
                            unit: ing.unit || '',
                            originalUnit: originalUnit
                        };
                    }
                });
            });
        });
        
        // Get category IDs and sort them
        const categoryIds = Object.keys(ingredientsByCategory);
        
        if (categoryIds.length > 0) {
            // Display ingredients grouped by category
            categoryIds.forEach(function(categoryId) {
                const category = ingredientsByCategory[categoryId];
                const categoryName = category.category_name || 'بغیر زمرہ';
                const ingredients = Object.values(category.ingredients);
                
                // Sort ingredients alphabetically by name
                ingredients.sort((a, b) => {
                    const nameA = a.ingredient_name || '';
                    const nameB = b.ingredient_name || '';
                    return nameA.localeCompare(nameB);
                });
                
                if (ingredients.length > 0) {
                    // Category header
                    ingredientsHtml += '<div class="category-section">';
                    ingredientsHtml += '<div class="category-header">' + categoryName + '</div>';
                    
                    // Ingredients grid for this category
                    ingredientsHtml += '<div class="ingredients-grid">';
                    
                    ingredients.forEach(function(ing) {
                        let quantity = parseFloat(ing.quantity) || 0;
                        // Use originalUnit (from database) if available, otherwise use unit
                        let unit = ing.originalUnit || ing.unit || '';
                        
                        // Function to translate unit to Urdu
                        function translateUnitToUrdu(unit) {
                            if (!unit) return '';
                            const unitLower = unit.toLowerCase().trim();
                            
                            const unitTranslations = {
                                'kg': 'کلو',
                                'kilogram': 'کلو',
                                'kilograms': 'کلو',
                                'g': 'گرام',
                                'gram': 'گرام',
                                'grams': 'گرام',
                                'piece': 'عدد',
                                'pieces': 'عدد',
                                'serving': 'حصہ',
                                'servings': 'حصے',
                                'portion': 'حصہ',
                                'portions': 'حصے',
                                'item': 'شے',
                                'items': 'اشیاء',
                                'ml': 'ملی لیٹر',
                                'milliliter': 'ملی لیٹر',
                                'milliliters': 'ملی لیٹر',
                                'l': 'لیٹر',
                                'liter': 'لیٹر',
                                'liters': 'لیٹر',
                                'litre': 'لیٹر',
                                'litres': 'لیٹر',
                                'meter': 'میٹر',
                                'meters': 'میٹر',
                                'metre': 'میٹر',
                                'metres': 'میٹر',
                                'میٹر': 'میٹر',
                                'عدد': 'عدد',
                                'گچھی': 'گچھی',
                                'guchhi': 'گچھی',
                                'bunch': 'گچھی',
                                'کلو': 'کلو',
                                'لیٹر': 'لیٹر',
                                'دیگ': 'دیگ',
                                'ڈیگ': 'دیگ',
                                'deg': 'دیگ',
                                'گرام': 'گرام'
                            };
                            return unitTranslations[unitLower] || unit;
                        }
                        
                        // Format quantity based on unit type
                        // Use original unit from database, only convert g to kg when >= 1000
                        let unitLower = unit.toLowerCase().trim();
                        const gramUnits = ['g', 'gram', 'grams', 'گرام'];
                        let quantityUnit = '';
                        
                        // Only convert g to kg if quantity >= 1000, preserve all other units as-is
                        const isGramUnit = gramUnits.includes(unitLower) || unit === 'گرام';
                        if (isGramUnit && quantity >= 1000) {
                            // Convert grams to kg for display only
                            quantity = quantity / 1000;
                            unit = 'kg';
                            unitLower = 'kg';
                        }
                        
                        const finalUnitLower = unitLower;
                        
                        // Special handling for kg/kilogram: split into kilos and grams
                        if (finalUnitLower === 'kg' || finalUnitLower === 'kilogram' || finalUnitLower === 'kilograms' || unit === 'کلو') {
                            const totalKilos = parseFloat(quantity);
                            const wholeKilos = Math.floor(totalKilos);
                            const decimalPart = totalKilos - wholeKilos;
                            const grams = Math.round(decimalPart * 1000);
                            
                            if (wholeKilos > 0 && grams > 0) {
                                quantityUnit = wholeKilos + ' کلو اور ' + grams + ' گرام';
                            } else if (wholeKilos > 0) {
                                quantityUnit = wholeKilos + ' کلو';
                            } else if (grams > 0) {
                                quantityUnit = grams + ' گرام';
                            } else {
                                quantityUnit = '0 کلو';
                            }
                        } else {
                            let displayQuantity = quantity;
                            const numericQuantity = parseFloat(quantity);
                            const hasNumericQuantity = !isNaN(numericQuantity);
                            
                            if (hasNumericQuantity) {
                                // Check if unit is grams (English or Urdu)
                                const isGramUnitFinal = gramUnits.includes(finalUnitLower) || unit === 'گرام' || unit.trim() === 'گرام';
                                if (isGramUnitFinal) {
                                    // For grams, show as integer if whole number, otherwise show with decimals
                                    if (numericQuantity % 1 === 0) {
                                        displayQuantity = numericQuantity.toString();
                                    } else {
                                        displayQuantity = numericQuantity.toFixed(2).replace(/\.0+$/, '');
                                    }
                                } else {
                                    displayQuantity = Math.round(numericQuantity).toString();
                                }
                            }
                            
                            const unitUrdu = translateUnitToUrdu(unit);
                            quantityUnit = displayQuantity + (unitUrdu ? ' ' + unitUrdu : '');
                        }
                        
                        // Calculation details are hidden from display as requested
                        const ingredientName = ing.ingredient_name || 'N/A';
                        
                        // Format for 6-column layout: Clean aligned display
                        ingredientsHtml += '<div class="ingredient-item">';
                        ingredientsHtml += '<div class="name">' + ingredientName + '</div>';
                        ingredientsHtml += '<div class="quantity">' + quantityUnit + '</div>';
                        ingredientsHtml += '</div>';
                    });
                    
                    ingredientsHtml += '</div>'; // Close grid
                    ingredientsHtml += '</div>'; // Close category section
                }
            });
        } else {
            ingredientsHtml += '<p style="text-align: center; color: #64748b; font-size: 14px; margin: 10px 0; font-family: ' + fontFamily + ';">' + translations.no_ingredients_found + '</p>';
        }
    }
    
    ingredientsHtml += '</div>';
    
    // Format date and time for display
    function formatDateForPrint(dateString) {
        if (!dateString) return '';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('ur-PK', { year: 'numeric', month: '2-digit', day: '2-digit' });
        } catch (e) {
            return dateString;
        }
    }
    
    function formatTimeForPrint(dateString) {
        if (!dateString) return '';
        try {
            const date = new Date(dateString);
            return date.toLocaleTimeString('ur-PK', { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return dateString;
        }
    }
    
    // Get order date and time
    const orderDate = order.order_date ? formatDateForPrint(order.order_date) : '';
    const orderTime = order.order_date ? formatTimeForPrint(order.order_date) : '';
    const deliveryDate = order.delivery_date ? formatDateForPrint(order.delivery_date) : '';
    const shiftText = order.shift ? (shiftTranslations[order.shift] || order.shift) : '';
    
    const printWindow = window.open('', '_blank');
    // Get base URL for images
    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/admin/') + 1);
    printWindow.document.write(`
        <!DOCTYPE html>
        <html dir="rtl" lang="ur">
        <head>
            <base href="${baseUrl}">
            <title>${translations.ingredients_list} - ${translations.order_id} ${order.order_number || '#' + order.id}</title>
            <meta charset="UTF-8">
            <style>
                @media print {
                    @page {
                        size: Legal;
                        margin: 0.3cm;
                    }
                    * {
                        page-break-inside: avoid !important;
                    }
                    body { 
                        margin: 0 !important; 
                        padding: 0 !important; 
                        position: relative; 
                        font-size: 10px !important;
                        page-break-inside: avoid !important;
                    }
                    
                    body::before {
                        content: '' !important;
                        position: fixed !important;
                        top: 50% !important;
                        left: 50% !important;
                        transform: translate(-50%, -50%) !important;
                        width: 60% !important;
                        height: 60% !important;
                        min-width: 400px !important;
                        min-height: 400px !important;
                        background-image: url('images/watermark.jpg') !important;
                        background-repeat: no-repeat !important;
                        background-position: center center !important;
                        background-size: contain !important;
                        opacity: 0.15 !important;
                        z-index: -1 !important;
                        pointer-events: none !important;
                    }
                    .no-print { display: none !important; }
                    .header-image {
                        page-break-after: avoid !important;
                        page-break-inside: avoid !important;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                        margin-bottom: 10px !important;
                    }
                    .header-image img {
                        width: 100% !important;
                        height: auto !important;
                        object-fit: contain !important;
                    }
                    .order-details-table {
                        page-break-after: avoid !important;
                        page-break-inside: avoid !important;
                        margin: 5px 0 !important;
                        table-layout: fixed !important;
                    }
                    .order-details-table td {
                        width: 20% !important;
                        height: 35px !important;
                        padding: 6px 4px !important;
                        font-size: 10px !important;
                        vertical-align: middle !important;
                        overflow: hidden !important;
                        word-wrap: break-word !important;
                    }
                    .table-note {
                        text-align: center !important;
                        margin: 8px 0 !important;
                        font-size: 20px !important;
                        font-weight: bold !important;
                    }
                    .ingredients-section {
                        page-break-inside: avoid !important;
                        page-break-before: avoid !important;
                        margin-top: 5px !important;
                    }
                    .ingredients-title {
                        font-size: 18px !important;
                        margin-bottom: 6px !important;
                        margin-top: 3px !important;
                        line-height: 1.2 !important;
                    }
                    .category-section {
                        page-break-inside: avoid !important;
                        margin: 4px 0 !important;
                    }
                    .category-header {
                        font-size: 15px !important;
                        font-weight: 900 !important;
                        padding: 10px 14px !important;
                        margin: 0 0 8px 0 !important;
                        background-color: #4c51bf !important;
                        background: #4c51bf !important;
                        color: #ffffff !important;
                        border: 3px solid #2d3748 !important;
                        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3) !important;
                        text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.5) !important;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                        color-adjust: exact !important;
                    }
                    [style*="grid-template-columns"] {
                        display: grid !important;
                        grid-template-columns: repeat(5, 1fr) !important;
                        gap: 3px !important;
                    }
                    [style*="grid-template-columns"] > div {
                        page-break-inside: avoid !important;
                        padding: 3px 4px !important;
                    }
                    .ingredient-item {
                        padding: 5px 6px !important;
                    }
                    .ingredient-item .name {
                        font-size: 13px !important;
                        margin-bottom: 3px !important;
                    }
                    .ingredient-item .quantity {
                        font-size: 11px !important;
                    }
                }
                body { 
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif; 
                    padding: 0; 
                    margin: 0; 
                    position: relative; 
                    direction: rtl; 
                    background: #fff;
                }
                
                body::before {
                    content: '';
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    width: 60%;
                    height: 60%;
                    min-width: 400px;
                    min-height: 400px;
                    background-image: url('images/watermark.jpg');
                    background-repeat: no-repeat;
                    background-position: center center;
                    background-size: contain;
                    opacity: 0.15;
                    z-index: -1;
                    pointer-events: none;
                }
                
                /* Header Image */
                .header-image {
                    width: 100%;
                    margin-bottom: 12px;
                    display: block;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }
                .header-image img {
                    width: 100%;
                    height: auto;
                    display: block;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }
                
                /* Order Details Table */
                .order-details-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 8px 0;
                    border: 1px solid #ddd;
                    background-color: #fff;
                    table-layout: fixed;
                }
                .order-details-table td {
                    width: 20%;
                    height: 35px;
                    padding: 8px 6px;
                    border-left: 1px solid #ddd;
                    border-right: 1px solid #ddd;
                    text-align: center;
                    border-bottom: 1px dotted #999;
                    background-color: #fff;
                    font-size: 11px;
                    vertical-align: middle;
                    overflow: hidden;
                    word-wrap: break-word;
                }
                .order-details-table tbody tr:first-child td {
                    background-color: #e9ecef;
                    font-weight: normal;
                    font-size: 12px;
                }
                .order-details-table tbody tr:first-child td strong {
                    font-weight: bold;
                    margin-left: 5px;
                }
                .order-details-table tbody tr:last-child td {
                    border-bottom: 1px solid #ddd;
                }
                
                /* Ingredients Section */
                .ingredients-section {
                    margin-top: 10px;
                    page-break-before: avoid;
                }
                .ingredients-title {
                    font-size: 20px;
                    font-weight: bold;
                    text-align: center;
                    margin-bottom: 10px;
                    margin-top: 5px;
                    color: #1e293b;
                    line-height: 1.3;
                }
                .category-section {
                    margin-top: 10px;
                    margin-bottom: 8px;
                }
                .category-header {
                    font-size: 17px;
                    font-weight: 900;
                    color: #ffffff;
                    padding: 12px 16px;
                    background: #4c51bf;
                    background-color: #4c51bf;
                    border-radius: 6px;
                    margin: 0 0 10px 0;
                    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
                    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
                    border: 3px solid #2d3748;
                    letter-spacing: 0.8px;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                    color-adjust: exact;
                }
                .ingredients-grid {
                    display: grid;
                    grid-template-columns: repeat(5, 1fr);
                    gap: 4px;
                    margin-bottom: 8px;
                }
                .ingredient-item {
                    padding: 6px 8px;
                    border: 1px solid #e2e8f0;
                    border-radius: 3px;
                    background-color: #ffffff;
                }
                .ingredient-item .name {
                    font-size: 14px;
                    font-weight: bold;
                    color: #1e293b;
                    margin-bottom: 3px;
                    line-height: 1.4;
                }
                .ingredient-item .quantity {
                    font-size: 12px;
                    color: #8b5cf6;
                    font-weight: 600;
                }
                
                .table-note {
                    text-align: center;
                    margin: 10px 0;
                    font-size: 22px;
                    color: #1e293b;
                    font-weight: bold;
                }
                
                .print-btn { 
                    margin: 15px 0; 
                    text-align: center; 
                }
                button { 
                    padding: 8px 16px; 
                    background: #8b5cf6; 
                    color: white; 
                    border: none; 
                    cursor: pointer; 
                    border-radius: 5px; 
                    font-size: 12px; 
                    margin: 0 5px;
                }
                button:hover { 
                    background: #7c3aed; 
                }
            </style>
        </head>
        <body>
            <!-- Header Image -->
            <div class="header-image">
                <img src="images/newimage.png" alt="Header Banner" onerror="console.error('Failed to load header image:', this.src);">
            </div>
            
            <!-- Order Details Table -->
            <table class="order-details-table">
                <tbody>
                    <!-- First row with header and value in same cell -->
                    <tr>
                        <td><strong>گایک:</strong> ${order.customer_name || ''}${order.customer_cell ? ' (' + order.customer_cell + ')' : ''}</td>
                        <td><strong>افراد:</strong> ${totalPersons > 0 ? totalPersons : ''}</td>
                        <td><strong>تاريخ:</strong> ${deliveryDate}</td>
                        <td><strong>شفٹ:</strong> ${shiftText}</td>
                        <td><strong>وقت:</strong> ${orderTime}</td>
                    </tr>
                    <!-- Additional rows with dish names -->
                    ${(() => {
                        const dishes = order.dishes && order.dishes.length > 0 ? order.dishes : [];
                        let rows = '';
                        
                        // Fill columns sequentially: 4 dishes per column
                        // Column 1: dishes 0-3, Column 2: dishes 4-7, Column 3: dishes 8-11, etc.
                        const dishesPerColumn = 4;
                        const numColumns = 5;
                        const numRows = dishesPerColumn;
                        
                        // Create rows, filling columns sequentially
                        for (let row = 0; row < numRows; row++) {
                            let cells = '';
                            for (let col = 0; col < numColumns; col++) {
                                const dishIndex = col * dishesPerColumn + row; // Column 0: 0-3, Column 1: 4-7, etc.
                                if (dishIndex < dishes.length) {
                                    const dish = dishes[dishIndex];
                                    const dishName = dish ? (dish.dish_name || '') : '';
                                    const dishQuantity = dish ? (parseFloat(dish.quantity) || 0) : 0;
                                    const dishUnit = dish ? (dish.unit || '') : '';
                                    const displayText = dishName + (dishQuantity > 0 ? ' (' + dishQuantity + (dishUnit ? ' ' + dishUnit : '') + ')' : '');
                                    cells += `<td>${displayText}</td>`;
                                } else {
                                    cells += `<td></td>`;
                                }
                            }
                            rows += `<tr>${cells}</tr>`;
                        }
                        return rows;
                    })()}
                </tbody>
            </table>
            
            <!-- Table Note -->
            <div class="table-note">
                (نوٹ :مرغی وزن سوا تا ڈیڑھ کلو)
            </div>
            
            <!-- Ingredients List Section -->
            <div class="ingredients-section">
                ${ingredientsHtml}
            </div>
            
            <div class="print-btn no-print">
                <button onclick="window.print()" style="margin-right: 10px; padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                    <i class="bi bi-printer-fill" style="margin-right: 5px;"></i>${translations.print || 'Print'}
                </button>
                <button onclick="shareIngredientsPDFForOrder('${order.order_number || '#' + order.id}')" style="margin-right: 10px; padding: 8px 16px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                    <i class="bi bi-share-fill" style="margin-right: 5px;"></i>Share PDF
                </button>
                <button onclick="window.close()" style="padding: 8px 16px; background: #6b7280; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                    ${translations.close || 'Close'}
                </button>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    
    // Add share function to the print window
    printWindow.shareIngredientsPDFForOrder = function(orderNumber) {
        // First, trigger print dialog so user can save as PDF
        printWindow.print();
        
        // After a short delay, show share options
        setTimeout(() => {
            const shareTitle = (translations.ingredients_list || 'Ingredients List') + ' - ' + (translations.order_id || 'Order ID') + ' ' + orderNumber;
            const shareText = (translations.ingredients_share_text || 'Ingredients list for order') + ' ' + orderNumber + '. Please save as PDF from the print dialog and share it.';
            
            if (navigator.share) {
                navigator.share({
                    title: shareTitle,
                    text: shareText,
                    url: printWindow.location.href
                }).catch(err => {
                    if (err.name !== 'AbortError') {
                        alert('Please save the PDF from the print dialog, then share it manually.');
                    }
                });
            } else if (navigator.clipboard) {
                navigator.clipboard.writeText(printWindow.location.href)
                    .then(() => alert('Link copied! Please save as PDF from print dialog (Ctrl+P > Save as PDF), then share the PDF file.'))
                    .catch(() => alert('Please save as PDF from the print dialog (Ctrl+P > Save as PDF), then share the PDF file.'));
            } else {
                alert('Please save as PDF from the print dialog (Ctrl+P or Cmd+P > Save as PDF), then share the PDF file manually.');
            }
        }, 500);
    };
    
    setTimeout(() => printWindow.print(), 250);
}

// Print Order Function
function printOrder(orderNumberOrId) {
    if (!ordersData || ordersData.length === 0) {
        alert('No orders data available.');
        return;
    }
    // Find by order_number or id
    const order = ordersData.find(o => o.order_number == orderNumberOrId || o.id == orderNumberOrId);
    if (!order) {
        alert('Order not found.');
        return;
    }
    
    // Get the base path for the image from PHP
    const logoPath = '<?php echo htmlspecialchars($logoPath, ENT_QUOTES); ?>';
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    const cakeImagePath = basePath + 'images/cake.png';
    // Use relative path - base tag in print window will handle it
    const bannerImagePath = 'images/newimage.png';
    
    // Get status translation
    const statusTranslations = <?php echo json_encode([
        'pending' => t('pending'),
        'confirmed' => t('confirmed'),
        'preparing' => t('preparing'),
        'ready' => t('ready'),
        'delivered' => t('delivered'),
        'cancelled' => t('cancelled')
    ]); ?>;
    
    // Determine text alignment and font based on language direction
    const textAlign = langDir === 'rtl' ? 'right' : 'left';
    const textAlignOpposite = langDir === 'rtl' ? 'left' : 'right';
    const fontFamily = langDir === 'rtl' ? 'Arial, "Noto Sans Arabic", "Segoe UI", Tahoma, sans-serif' : 'Arial, sans-serif';
    const orderStatus = statusTranslations[order.status] || order.status;
    
    // Get number of persons from order level (not from dish)
    const totalPersons = parseInt(order.number_of_persons) || 0;
    
    const printWindow = window.open('', '_blank');
    // Get base URL for images
    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/admin/') + 1);
    printWindow.document.write(`
        <!DOCTYPE html>
        <html dir="${langDir}">
        <head>
            <base href="${baseUrl}">
            <title>${translations.order_receipt} - ${translations.order_id} ${order.order_number || '#' + order.id}</title>
            <meta charset="UTF-8">
            <style>
                @media print {
                    @page {
                        size: Legal;
                        margin: 0.5cm;
                    }
                    body { margin: 0; padding: 15px; position: relative; font-size: 14px !important; }
                    .no-print { display: none; }
                    .print-banner { min-height: 120px !important; margin-bottom: 15px !important; }
                    .banner-left-name { font-size: 20px !important; margin-bottom: 6px !important; }
                    .banner-left-phone { font-size: 13px !important; }
                    .banner-right-service { font-size: 15px !important; }
                    .banner-right-service.yellow { font-size: 17px !important; }
                    .banner-address-bar { padding: 8px 12px !important; margin-top: 8px !important; }
                    .banner-address-text { font-size: 12px !important; }
                    .dish-names-section {
                        margin: 8px 0 !important;
                        padding: 8px !important;
                        page-break-inside: avoid !important;
                    }
                    .dish-names-section h2 {
                        font-size: 13px !important;
                        margin-bottom: 6px !important;
                        padding: 3px 0 !important;
                    }
                    .dish-names-section [style*="grid-template-columns"] {
                        grid-template-columns: repeat(2, 1fr) !important;
                        gap: 6px !important;
                    }
                    .dish-names-section [style*="grid-template-columns"] > div {
                        padding: 6px 8px !important;
                        font-size: 13px !important;
                    }
                    .fillable-section { 
                        margin: 15px 0 !important; 
                        padding: 12px !important;
                        display: grid !important;
                        grid-template-columns: repeat(2, 1fr) !important;
                        gap: 10px !important;
                    }
                    .fillable-field { margin-bottom: 0 !important; }
                    .fillable-label { font-size: 15px !important; }
                    .fillable-space { height: 28px !important; }
                    .header { margin-bottom: 15px !important; padding-bottom: 12px !important; }
                    .header h1 { font-size: 22px !important; }
                    .header p { font-size: 15px !important; margin: 5px 0 !important; }
                    .order-info { margin: 15px 0 !important; }
                    .order-info p { margin: 8px 0 !important; font-size: 13px !important; }
                    .order-details { padding: 18px !important; margin: 15px 0 !important; }
                    .order-details h3 { font-size: 18px !important; margin-top: 0 !important; }
                    .detail-row { margin: 10px 0 !important; padding: 8px 0 !important; font-size: 13px !important; }
                    .total-section { margin-top: 18px !important; padding-top: 18px !important; }
                    .total-row { font-size: 20px !important; margin: 12px 0 !important; }
                    .notes { margin-top: 15px !important; padding: 12px !important; font-size: 13px !important; }
                    .footer { margin-top: 18px !important; padding-top: 15px !important; font-size: 13px !important; }
                }
                body { font-family: ${fontFamily}; padding: 18px; max-width: 800px; margin: 0 auto; position: relative; direction: ${langDir}; font-size: 14px; }
                
                /* Banner Header - Visible and readable */
                .print-banner {
                    display: flex !important;
                    width: 100%;
                    margin-bottom: 12px;
                    border: 2px solid #000;
                    overflow: visible;
                    box-sizing: border-box;
                    min-height: 110px;
                    background: white;
                }
                .banner-left {
                    width: 25%;
                    background: white;
                    padding: 10px 8px;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    align-items: center;
                    box-sizing: border-box;
                    border-right: 2px solid #000;
                }
                .banner-left-name {
                    font-size: 22px;
                    font-weight: 900;
                    color: #000;
                    margin-bottom: 8px;
                    text-align: center;
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif;
                    line-height: 1.4;
                }
                .banner-left-phone {
                    font-size: 14px;
                    color: #000;
                    margin: 4px 0;
                    text-align: center;
                    direction: ltr;
                    font-weight: bold;
                    font-family: Arial, sans-serif;
                }
                .banner-right {
                    width: 75%;
                    background: white;
                    padding: 10px 15px;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    align-items: center;
                    position: relative;
                    box-sizing: border-box;
                }
                .banner-right-service {
                    color: #666;
                    font-size: 16px;
                    font-weight: 700;
                    margin: 4px 0;
                    text-align: center;
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif;
                    line-height: 1.4;
                    text-shadow: 1px 1px 1px rgba(0,0,0,0.1);
                }
                .banner-right-service.yellow {
                    color: #FFD700;
                    font-size: 18px;
                    font-weight: 900;
                    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
                }
                .banner-address-bar {
                    background: white;
                    padding: 8px 12px;
                    border: 1px solid #ccc;
                    border-radius: 5px;
                    margin-top: 8px;
                    width: calc(100% - 70px);
                    text-align: center;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }
                .banner-address-text {
                    color: #666;
                    font-size: 11px;
                    font-weight: 600;
                    line-height: 1.4;
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif;
                }
                .banner-dessert-graphic {
                    position: absolute;
                    right: 15px;
                    top: 50%;
                    transform: translateY(-50%);
                    width: 60px;
                    height: 60px;
                    background: white;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                    border: 2px solid #ccc;
                    z-index: 10;
                    overflow: hidden;
                }
                .banner-dessert-graphic img {
                    width: 55px;
                    height: 55px;
                    object-fit: contain;
                    border-radius: 50%;
                }
                .fillable-section {
                    margin: 12px 0;
                    padding: 10px;
                    border: 2px dashed #ccc;
                    border-radius: 5px;
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 8px;
                }
                .fillable-field {
                    display: flex;
                    align-items: center;
                    margin-bottom: 0;
                    direction: rtl;
                }
                .fillable-label {
                    font-weight: bold;
                    font-size: 16px;
                    color: #333;
                    min-width: 100px;
                    margin-left: 15px;
                }
                .fillable-space {
                    flex: 1;
                    border-bottom: 2px solid #000;
                    height: 30px;
                    margin: 0 12px;
                }
                
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #1e293b; padding-bottom: 15px; position: relative; z-index: 1; }
                .header h1 { margin: 0; color: #1e293b; font-size: 24px; }
                .header p { margin: 6px 0; color: #64748b; font-size: 16px; }
                .order-info { margin: 18px 0; position: relative; z-index: 1; }
                .order-info p { margin: 8px 0; font-size: 14px; }
                .order-details { background: #f8fafc; padding: 20px; border-radius: 5px; margin: 18px 0; position: relative; z-index: 1; }
                .order-details h3 { margin-top: 0; font-size: 20px; }
                .detail-row { display: flex; justify-content: space-between; margin: 12px 0; padding: 8px 0; border-bottom: 1px solid #e2e8f0; flex-direction: ${langDir === 'rtl' ? 'row-reverse' : 'row'}; font-size: 14px; }
                .detail-row:last-child { border-bottom: none; }
                .detail-label { font-weight: bold; }
                .category-badge { display: inline-block; padding: 4px 10px; background: #e0e7ff; color: #4338ca; border-radius: 12px; font-size: 12px; font-weight: 600; margin-left: 8px; }
                ${langDir === 'rtl' ? '.notes { border-left: none; border-right: 4px solid #f59e0b; }' : ''}
                .total-section { margin-top: 20px; padding-top: 20px; border-top: 2px solid #1e293b; position: relative; z-index: 1; }
                .total-row { display: flex; justify-content: space-between; font-size: 22px; font-weight: bold; margin: 12px 0; flex-direction: ${langDir === 'rtl' ? 'row-reverse' : 'row'}; }
                .status-badge { display: inline-block; padding: 6px 14px; border-radius: 20px; font-weight: bold; margin-top: 10px; font-size: 13px; }
                .status-pending { background: #f59e0b; color: #fff; }
                .status-confirmed { background: #f97316; color: #fff; }
                .status-preparing { background: #64748b; color: #fff; }
                .status-ready { background: #10b981; color: #fff; }
                .status-delivered { background: #10b981; color: #fff; }
                .status-cancelled { background: #ef4444; color: #fff; }
                .footer { margin-top: 20px; text-align: center; color: #64748b; font-size: 14px; border-top: 1px solid #e2e8f0; padding-top: 15px; position: relative; z-index: 1; }
                .print-btn { margin: 25px 0; text-align: center; }
                button { padding: 12px 24px; background: #8b5cf6; color: white; border: none; cursor: pointer; border-radius: 5px; margin: 0 8px; font-size: 15px; }
                button:hover { background: #7c3aed; }
                .notes { margin-top: 15px; padding: 12px; background: #fef3c7; border-left: 4px solid #f59e0b; font-size: 14px; }
            </style>
        </head>
        <body>
            <!-- Print Banner -->
            <div class="print-banner">
                <img src="${bannerImagePath}" alt="Advertisement Banner" class="print-banner-image" style="width: 100%; height: auto; display: block; -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact;" onerror="console.error('Failed to load banner image:', this.src);">
            </div>
            
            <!-- Fillable Fields Section with Dish Names -->
            <div class="fillable-section">
                ${order.dishes.map(dish => {
                    const dishName = dish.dish_name || 'N/A';
                    const categoryName = dish.category_name || 'Uncategorized';
                    const quantity = parseFloat(dish.quantity) || 1;
                    const quantityText = ' ' + quantity.toFixed(2) + ' دیگ';
                    return `
                    <div class="fillable-field">
                        <span class="fillable-label">${translations.dish || 'Dish'}:</span>
                        <div class="fillable-space" style="text-align: center; font-weight: bold;">${dishName}${quantityText} <span class="category-badge">${categoryName}</span></div>
                    </div>
                    `;
                }).join('')}
                <div class="fillable-field">
                    <span class="fillable-label">تاریخ:</span>
                    <div class="fillable-space" style="text-align: center; font-weight: bold;">${order.order_date ? formatDateForPrint(order.order_date) : formatDateForPrint(new Date().toISOString())}</div>
                </div>
                <div class="fillable-field">
                    <span class="fillable-label">وقت:</span>
                    <div class="fillable-space" style="text-align: center; font-weight: bold;">${order.order_date ? formatTimeForPrint(order.order_date) : formatTimeForPrint(new Date().toISOString())}</div>
                </div>
                <div class="fillable-field">
                    <span class="fillable-label">${translations.number_of_persons}:</span>
                    <div class="fillable-space" style="text-align: center; font-weight: bold;">${totalPersons > 0 ? totalPersons : ''}</div>
                </div>
            </div>
            
            <div class="header">
                <h1>${translations.brand_name}</h1>
                <p>${translations.order_receipt}</p>
            </div>
            <div class="order-info">
                <p><strong>${translations.order_id}:</strong> ${order.order_number || '#' + order.id}</p>
                <p><strong>${translations.order_date}:</strong> ${order.order_date ? formatDateTimeForPrint(order.order_date) : new Date().toLocaleString()}</p>
                <p><strong>${translations.status}:</strong> <span class="status-badge status-${order.status}">${orderStatus}</span></p>
            </div>
            <div class="order-details">
                <h3>${translations.order_details}</h3>
                <div class="detail-row">
                    <span class="detail-label">${translations.customer}:</span>
                    <span>${order.customer_name || 'N/A'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">${translations.email}:</span>
                    <span>${order.customer_email || 'N/A'}</span>
                </div>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
                    <h3 style="font-size: 14px; font-weight: bold; color: #64748b; margin-bottom: 10px;">${translations.dish} ${translations.details || 'Details'}:</h3>
                    ${order.dishes.map(dish => {
                        const persons = parseInt(dish.number_of_persons) || 1;
                        const dishName = dish.dish_name || 'N/A';
                        const categoryName = dish.category_name || 'Uncategorized';
                        return `
                        <div class="detail-row" style="margin-left: 20px; margin-bottom: 10px; padding: 12px; background-color: #f0fdf4; border-left: 3px solid #10b981; border-radius: 4px;">
                            <div style="flex: 1;">
                                <div style="font-size: 15px; font-weight: bold; color: #1e293b; margin-bottom: 6px;">${dishName} <span class="category-badge">${categoryName}</span></div>
                                <div style="color: #64748b; font-size: 13px; line-height: 1.6;">
                                    <span>${translations.quantity}: <strong>${dish.quantity}</strong>${dish.unit ? ' ' + dish.unit : ''}</span>
                                    <span style="margin: 0 10px;">|</span>
                                    <span>${translations.persons}: <strong>${persons}</strong></span>
                                    ${dish.total_amount > 0 ? '<span style="margin: 0 10px;">|</span><span>Rs <strong>' + parseFloat(dish.total_amount).toFixed(2) + '</strong></span>' : ''}
                                </div>
                            </div>
                        </div>
                    `;
                    }).join('')}
                </div>
                ${order.notes ? '<div class="notes"><strong>' + (translations.notes || 'Notes') + ':</strong> ' + order.notes + '</div>' : ''}
            </div>
            <div class="total-section">
                <div class="total-row">
                    <span>${translations.total_amount}:</span>
                    <span>Rs ${parseFloat(order.total_amount).toFixed(2)}</span>
                </div>
            </div>
            <div class="footer">
                <p>${translations.thank_you}</p>
                <p>${translations.printed_on}: ${new Date().toLocaleString()}</p>
            </div>
            <div class="print-btn no-print">
                <button onclick="window.print()">${translations.print}</button>
                <button onclick="window.close()">${translations.close}</button>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    setTimeout(() => printWindow.print(), 250);
}

// Share order details using Web Share API or clipboard fallback
function shareOrder(orderNumber) {
    const previewUrl = new URL('order_preview.php', window.location.href);
    previewUrl.searchParams.set('order_number', orderNumber);
    const shareData = {
        title: `Order ${orderNumber}`,
        text: `Order details for ${orderNumber}`,
        url: previewUrl.toString()
    };

    if (navigator.share) {
        navigator.share(shareData).catch(err => {
            if (err.name !== 'AbortError') {
                alert('Sharing failed. Please copy the link instead.');
            }
        });
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(previewUrl.toString())
            .then(() => alert('Link copied to clipboard!'))
            .catch(() => alert('Unable to copy link. Please copy it manually: ' + previewUrl.toString()));
    } else {
        alert('Sharing is not supported in this browser.');
    }
}

// Helper functions to format date and time for print
function formatDateForPrint(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}/${month}/${year}`;
}

function formatTimeForPrint(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
}

function formatDateTimeForPrint(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleString('en-GB', { 
        day: '2-digit', 
        month: '2-digit', 
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    });
}

// Dish Selection Modal Functions
let dishSelectionModal = null;
let currentSelectedCategoryId = null;
let currentDishRowIndex = 0;

// Open dish selection modal
function loadModalDishImages(scope) {
    const root = scope || document;
    root.querySelectorAll('img.modal-dish-lazy-img[data-src]').forEach(function (img) {
        const parent = img.closest('.modal-dish-item');
        if (parent && parent.style.display === 'none') {
            return;
        }
        if (!img.getAttribute('src')) {
            img.setAttribute('src', img.getAttribute('data-src'));
        }
    });
}

function openDishSelectionModal(rowIndex) {
    currentDishRowIndex = rowIndex || 0;
    currentSelectedCategoryId = null;
    
    if (!dishSelectionModal) {
        const modalElement = document.getElementById('dishSelectionModal');
        if (modalElement) {
            dishSelectionModal = new bootstrap.Modal(modalElement);
        }
    }
    if (dishSelectionModal) {
        dishSelectionModal.show();
        // Reset to show categories
        showCategoriesInModal();
    }
}

// Show categories in modal
function showCategoriesInModal() {
    currentSelectedCategoryId = null;
    const categoriesGrid = document.getElementById('modalCategoriesGrid');
    const dishesGrid = document.getElementById('modalDishesGrid');
    const backBtn = document.getElementById('backToCategoriesBtn');
    const searchInput = document.getElementById('dishSearchInput');
    
    if (categoriesGrid) categoriesGrid.style.display = 'block';
    if (dishesGrid) dishesGrid.style.display = 'none';
    if (backBtn) backBtn.style.display = 'none';
    if (searchInput) {
        searchInput.value = '';
        searchInput.placeholder = 'Search categories...';
    }
    
    // Update modal title
    const modalTitle = document.getElementById('dishSelectionModalLabel');
    if (modalTitle) {
        modalTitle.innerHTML = '<i class="bi bi-folder me-2"></i>Select Category';
    }
    
    filterItemsInModal('');
}

// Select category in modal and show dishes
function selectCategoryInModal(categoryId, categoryName) {
    currentSelectedCategoryId = categoryId;
    const categoriesGrid = document.getElementById('modalCategoriesGrid');
    const dishesGrid = document.getElementById('modalDishesGrid');
    const backBtn = document.getElementById('backToCategoriesBtn');
    const searchInput = document.getElementById('dishSearchInput');
    
    if (categoriesGrid) categoriesGrid.style.display = 'none';
    if (dishesGrid) dishesGrid.style.display = 'block';
    if (backBtn) backBtn.style.display = 'block';
    if (searchInput) {
        searchInput.value = '';
        searchInput.placeholder = 'Search dishes...';
    }
    
    // Update modal title
    const modalTitle = document.getElementById('dishSelectionModalLabel');
    if (modalTitle) {
        modalTitle.innerHTML = `<i class="bi bi-egg-fried me-2"></i>Select Dish - ${categoryName}`;
    }
    
    // Filter dishes by category
    const dishItems = document.querySelectorAll('.modal-dish-item');
    dishItems.forEach(item => {
        const itemCategoryId = item.getAttribute('data-category-id');
        if (categoryId == 0) {
            // Show uncategorized dishes
            item.style.display = (!itemCategoryId || itemCategoryId == '0') ? 'block' : 'none';
        } else {
            // Show dishes from selected category
            item.style.display = (itemCategoryId == categoryId) ? 'block' : 'none';
        }
    });
    
    filterItemsInModal('');
    loadModalDishImages(dishesGrid);
}

// Filter items in modal (categories or dishes) by search term
function filterItemsInModal(searchTerm) {
    const searchLower = searchTerm.toLowerCase().trim();
    let visibleCount = 0;
    
    if (currentSelectedCategoryId === null) {
        // Filtering categories
        const categoryItems = document.querySelectorAll('.modal-category-item');
        categoryItems.forEach(item => {
            const categoryName = item.getAttribute('data-category-name').toLowerCase();
            const matchesSearch = !searchTerm || categoryName.includes(searchLower);
            
            if (matchesSearch) {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
    } else {
        // Filtering dishes
        const dishItems = document.querySelectorAll('.modal-dish-item');
        dishItems.forEach(item => {
            // Only filter visible dishes (already filtered by category)
            if (item.style.display === 'none') return;
            
            const dishName = item.getAttribute('data-dish-name').toLowerCase();
            const category = item.getAttribute('data-category').toLowerCase();
            const matchesSearch = !searchTerm || dishName.includes(searchLower) || category.includes(searchLower);
            
            if (matchesSearch) {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
    }
    
    // Show/hide no results message
    const noResults = document.getElementById('noItemsFound');
    if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

// Clear search
function clearDishSearch() {
    const searchInput = document.getElementById('dishSearchInput');
    if (searchInput) {
        searchInput.value = '';
        filterItemsInModal('');
    }
}

// Select dish from modal
function selectDishFromModal(dishId, dishName) {
    // Find the select field for the current row
    const row = document.querySelector(`.dish-row[data-row="${currentDishRowIndex}"]`);
    if (row) {
        const select = row.querySelector('.dish-select');
        if (select) {
            select.value = dishId;
            // Trigger change event to update any dependent fields
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (typeof updateDishThumb === 'function') {
            updateDishThumb(row);
        }
        if (typeof renderDishIngredientsPanel === 'function') {
            renderDishIngredientsPanel(row);
        }
    }
    
    // Close modal
    if (dishSelectionModal) {
        dishSelectionModal.hide();
    }
    
    // Update review if on step 4
    if (typeof updateReview === 'function' && typeof currentStep !== 'undefined' && currentStep === 4) {
        updateReview();
    }
}

// Add new dish row function (called from button)
function addNewDishRow() {
    const addDishBtn = document.getElementById('addDishBtn');
    if (addDishBtn) {
        addDishBtn.click();
    }
}
