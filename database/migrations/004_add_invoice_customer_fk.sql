-- Add foreign key constraint to op_invoices for customer referential integrity
ALTER TABLE `op_invoices`
ADD CONSTRAINT `fk_inv_customer`
FOREIGN KEY (`customer_id`) REFERENCES `op_customers` (`id`)
ON DELETE SET NULL;
